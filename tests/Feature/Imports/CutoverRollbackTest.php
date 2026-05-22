<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Livewire\Imports\Ploi\MigrationProgress;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Q13 cutover rollback affordance. When ImportSiteMigration is in
 * STATUS_CUTOVER_FAILED, the progress page surfaces "Roll back DNS" +
 * "Mark resolved manually" buttons. Roll back attempts to delete the
 * dply A record created during cutover_dns_swap; manual-resolution is
 * the escape hatch.
 */
class CutoverRollbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: ImportServerMigration, 3: ImportSiteMigration}
     */
    protected function seedCutoverFailed(string $dnsSwapStatus = 'succeeded', ?array $dnsResultData = null): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_CUTOVER_IN_PROGRESS,
        ]);
        $site = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_CUTOVER_FAILED,
            'source_snapshot' => [],
            'failure_summary' => 'Smoke test failed after 60 attempts.',
        ]);
        // Synthesize a successful DNS swap step with record_id so rollback has a target.
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $site->id,
            'sequence' => 60,
            'step_key' => ImportMigrationStep::KEY_CUTOVER_DNS_SWAP,
            'status' => $dnsSwapStatus,
            'result_data' => $dnsResultData ?? [
                'strategy' => 'automated',
                'credential' => 'digitalocean',
                'zone' => 'example.com',
                'record' => 'app',
                'record_id' => 7777,
                'new_ip' => '198.51.100.50',
            ],
        ]);

        return [$user, $org, $migration, $site];
    }

    public function test_progress_page_renders_rollback_actions_when_cutover_failed(): void
    {
        [$user, , $migration] = $this->seedCutoverFailed();

        $response = $this->actingAs($user)->get(route('imports.ploi.migration.progress', $migration));

        $response->assertOk()
            ->assertSee('Cutover failed.')
            ->assertSee('Smoke test failed after 60 attempts.')
            ->assertSee('Roll back DNS')
            ->assertSee('Mark resolved manually')
            ->assertSee('DNS for app.example.com currently points at dply');
    }

    public function test_rollback_calls_dns_adapter_and_flips_status_to_rolled_back(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/domains/example.com/records/7777' => Http::response('', 204),
        ]);

        [$user, $org, $migration, $site] = $this->seedCutoverFailed();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'dop_v1_test'],
        ]);

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('rollbackCutoverDns', $site->id)
            ->assertHasNoErrors();

        $site->refresh();
        $this->assertSame(ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK, $site->status);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
            && str_ends_with($req->url(), '/domains/example.com/records/7777'));
    }

    public function test_rollback_still_flips_status_when_delete_fails(): void
    {
        Http::fake([
            'https://api.digitalocean.com/v2/domains/example.com/records/7777' => Http::response('not found', 404),
        ]);

        [$user, $org, $migration, $site] = $this->seedCutoverFailed();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'dop_v1_test'],
        ]);

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('rollbackCutoverDns', $site->id);

        $this->assertSame(ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK, $site->fresh()->status);
    }

    public function test_rollback_rejects_site_not_in_cutover_failed(): void
    {
        [$user, , $migration, $site] = $this->seedCutoverFailed();
        $site->status = ImportSiteMigration::STATUS_COMPLETED;
        $site->save();

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('rollbackCutoverDns', $site->id);

        $this->assertSame(ImportSiteMigration::STATUS_COMPLETED, $site->fresh()->status);
    }

    public function test_mark_resolved_manually_flips_status_and_records_note(): void
    {
        [$user, , $migration, $site] = $this->seedCutoverFailed();

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('markCutoverResolvedManually', $site->id);

        $site->refresh();
        $this->assertSame(ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK, $site->status);
        $this->assertStringContainsString('Manually resolved by user.', $site->failure_summary);
    }

    public function test_step_orchestrator_marks_site_cutover_failed_when_cutover_step_fails(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
        ]);
        $migration = ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_CUTOVER_IN_PROGRESS,
        ]);
        $site = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS,
            'source_snapshot' => [],
        ]);
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $site->id,
            'sequence' => 70,
            'step_key' => ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);

        // Trigger the orchestrator with an unregistered key so it marks failed.
        $registry = new \App\Services\Imports\StepRegistry();
        $orchestrator = new \App\Services\Imports\StepOrchestrator($registry);
        $step->step_key = 'cutover_smoke_test'; // valid key, but no handler registered → fails.
        $step->save();
        $orchestrator->executeStep($step);

        $this->assertSame(ImportSiteMigration::STATUS_CUTOVER_FAILED, $site->fresh()->status);
        $this->assertNotNull($site->fresh()->failure_summary);
    }
}
