<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\CutoverRollbackTest;

use App\Modules\Imports\Livewire\Ploi\MigrationProgress;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Imports\Services\StepOrchestrator;
use App\Modules\Imports\Services\StepRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: ImportServerMigration, 3: ImportSiteMigration}
 */
function seedCutoverFailed(string $dnsSwapStatus = 'succeeded', ?array $dnsResultData = null): array
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
test('progress page renders rollback actions when cutover failed', function () {
    [$user, , $migration] = seedCutoverFailed();

    $response = $this->actingAs($user)->get(route('imports.ploi.migration.progress', $migration));

    $response->assertOk()
        ->assertSee('Cutover failed.')
        ->assertSee('Smoke test failed after 60 attempts.')
        ->assertSee('Roll back DNS')
        ->assertSee('Mark resolved manually')
        ->assertSee('DNS for app.example.com currently points at dply');
});
test('rollback calls dns adapter and flips status to rolled back', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/example.com/records/7777' => Http::response('', 204),
    ]);

    [$user, $org, $migration, $site] = seedCutoverFailed();
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
    expect($site->status)->toBe(ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK);
    Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
        && str_ends_with($req->url(), '/domains/example.com/records/7777'));
});
test('rollback still flips status when delete fails', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/domains/example.com/records/7777' => Http::response('not found', 404),
    ]);

    [$user, $org, $migration, $site] = seedCutoverFailed();
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('rollbackCutoverDns', $site->id);

    expect($site->fresh()->status)->toBe(ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK);
});
test('rollback rejects site not in cutover failed', function () {
    [$user, , $migration, $site] = seedCutoverFailed();
    $site->status = ImportSiteMigration::STATUS_COMPLETED;
    $site->save();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('rollbackCutoverDns', $site->id);

    expect($site->fresh()->status)->toBe(ImportSiteMigration::STATUS_COMPLETED);
});
test('mark resolved manually flips status and records note', function () {
    [$user, , $migration, $site] = seedCutoverFailed();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('markCutoverResolvedManually', $site->id);

    $site->refresh();
    expect($site->status)->toBe(ImportSiteMigration::STATUS_CUTOVER_ROLLED_BACK);
    $this->assertStringContainsString('Manually resolved by user.', $site->failure_summary);
});
test('step orchestrator marks site cutover failed when cutover step fails', function () {
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
    $registry = new StepRegistry;
    $orchestrator = new StepOrchestrator($registry);
    $step->step_key = 'cutover_smoke_test';
    // valid key, but no handler registered → fails.
    $step->save();
    $orchestrator->executeStep($step);

    expect($site->fresh()->status)->toBe(ImportSiteMigration::STATUS_CUTOVER_FAILED);
    expect($site->fresh()->failure_summary)->not->toBeNull();
});
