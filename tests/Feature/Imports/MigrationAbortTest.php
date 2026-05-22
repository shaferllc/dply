<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Jobs\Imports\RunMigrationStepJob;
use App\Livewire\Imports\Ploi\MigrationProgress;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Q13 abort path: a user-initiated tear-down of a non-terminal migration.
 * Pending steps → skipped, in-flight children → aborted, parent →
 * aborted, revoke_ssh_key dispatched immediately.
 */
class MigrationAbortTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: ImportServerMigration, 3: ImportSiteMigration}
     */
    protected function seedActiveMigration(): array
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
            'status' => ImportServerMigration::STATUS_STAGING,
            'ssh_key_source_id' => 9001,
            'ssh_key_pushed_at' => now()->subHour(),
        ]);
        $site = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_STAGING,
            'source_snapshot' => [],
        ]);
        // Two pending non-revoke steps + the revoke step that should fire on abort.
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $site->id,
            'sequence' => 10,
            'step_key' => ImportMigrationStep::KEY_CLONE_REPO,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $site->id,
            'sequence' => 11,
            'step_key' => ImportMigrationStep::KEY_COPY_ENV,
            'status' => ImportMigrationStep::STATUS_RUNNING,
        ]);
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'sequence' => 99,
            'step_key' => ImportMigrationStep::KEY_REVOKE_SSH_KEY,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);

        return [$user, $org, $migration, $site];
    }

    public function test_abort_marks_parent_aborted_and_cascades_state(): void
    {
        Bus::fake();
        [$user, , $migration, $site] = $this->seedActiveMigration();

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('abortMigration');

        $migration->refresh();
        $this->assertSame(ImportServerMigration::STATUS_ABORTED, $migration->status);
        $this->assertNotNull($migration->completed_at);
        $this->assertStringContainsString('Aborted by user via UI.', $migration->failure_summary);

        $this->assertSame(ImportSiteMigration::STATUS_ABORTED, $site->fresh()->status);

        // Non-revoke pending steps → skipped.
        $this->assertSame(2, ImportMigrationStep::query()
            ->where('import_server_migration_id', $migration->id)
            ->where('status', ImportMigrationStep::STATUS_SKIPPED)
            ->count());

        // Revoke step still pending — dispatched as the cleanup job.
        $this->assertSame(ImportMigrationStep::STATUS_PENDING, ImportMigrationStep::query()
            ->where('import_server_migration_id', $migration->id)
            ->where('step_key', ImportMigrationStep::KEY_REVOKE_SSH_KEY)
            ->first()->status);

        Bus::assertDispatched(RunMigrationStepJob::class, 1);
    }

    public function test_abort_is_rejected_when_migration_already_terminal(): void
    {
        Bus::fake();
        [$user, , $migration] = $this->seedActiveMigration();
        $migration->status = ImportServerMigration::STATUS_COMPLETED;
        $migration->save();

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('abortMigration');

        $this->assertSame(ImportServerMigration::STATUS_COMPLETED, $migration->fresh()->status);
        Bus::assertNotDispatched(RunMigrationStepJob::class);
    }

    public function test_request_abort_opens_confirmation_modal(): void
    {
        [$user, , $migration] = $this->seedActiveMigration();

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->assertSet('confirmingAbort', false)
            ->call('requestAbort')
            ->assertSet('confirmingAbort', true)
            ->call('cancelAbort')
            ->assertSet('confirmingAbort', false);
    }

    public function test_progress_page_renders_abort_button_only_for_active_migrations(): void
    {
        [$user, , $migration] = $this->seedActiveMigration();

        $this->actingAs($user)
            ->get(route('imports.ploi.migration.progress', $migration))
            ->assertOk()
            ->assertSee('Abort migration');

        $migration->status = ImportServerMigration::STATUS_COMPLETED;
        $migration->save();

        $this->actingAs($user)
            ->get(route('imports.ploi.migration.progress', $migration))
            ->assertOk()
            ->assertDontSee('Abort migration');
    }

    public function test_abort_does_not_dispatch_revoke_when_no_ssh_key_was_pushed(): void
    {
        Bus::fake();
        [$user, , $migration] = $this->seedActiveMigration();
        $migration->ssh_key_source_id = null;
        $migration->ssh_key_pushed_at = null;
        $migration->save();

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('abortMigration');

        $this->assertSame(ImportServerMigration::STATUS_ABORTED, $migration->fresh()->status);
        Bus::assertNotDispatched(RunMigrationStepJob::class);
    }
}
