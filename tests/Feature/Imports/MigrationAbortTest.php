<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\MigrationAbortTest;

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

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: ImportServerMigration, 3: ImportSiteMigration}
 */
function seedActiveMigration(): array
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
test('abort marks parent aborted and cascades state', function () {
    Bus::fake();
    [$user, , $migration, $site] = seedActiveMigration();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('abortMigration');

    $migration->refresh();
    expect($migration->status)->toBe(ImportServerMigration::STATUS_ABORTED);
    expect($migration->completed_at)->not->toBeNull();
    $this->assertStringContainsString('Aborted by user via UI.', $migration->failure_summary);

    expect($site->fresh()->status)->toBe(ImportSiteMigration::STATUS_ABORTED);

    // Non-revoke pending steps → skipped.
    expect(ImportMigrationStep::query()
        ->where('import_server_migration_id', $migration->id)
        ->where('status', ImportMigrationStep::STATUS_SKIPPED)
        ->count())->toBe(2);

    // Revoke step still pending — dispatched as the cleanup job.
    expect(ImportMigrationStep::query()
        ->where('import_server_migration_id', $migration->id)
        ->where('step_key', ImportMigrationStep::KEY_REVOKE_SSH_KEY)
        ->first()->status)->toBe(ImportMigrationStep::STATUS_PENDING);

    Bus::assertDispatched(RunMigrationStepJob::class, 1);
});
test('abort is rejected when migration already terminal', function () {
    Bus::fake();
    [$user, , $migration] = seedActiveMigration();
    $migration->status = ImportServerMigration::STATUS_COMPLETED;
    $migration->save();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('abortMigration');

    expect($migration->fresh()->status)->toBe(ImportServerMigration::STATUS_COMPLETED);
    Bus::assertNotDispatched(RunMigrationStepJob::class);
});
test('request abort opens confirmation modal', function () {
    [$user, , $migration] = seedActiveMigration();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->assertSet('confirmingAbort', false)
        ->call('requestAbort')
        ->assertSet('confirmingAbort', true)
        ->call('cancelAbort')
        ->assertSet('confirmingAbort', false);
});
test('progress page renders abort button only for active migrations', function () {
    [$user, , $migration] = seedActiveMigration();

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
});
test('abort does not dispatch revoke when no ssh key was pushed', function () {
    Bus::fake();
    [$user, , $migration] = seedActiveMigration();
    $migration->ssh_key_source_id = null;
    $migration->ssh_key_pushed_at = null;
    $migration->save();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('abortMigration');

    expect($migration->fresh()->status)->toBe(ImportServerMigration::STATUS_ABORTED);
    Bus::assertNotDispatched(RunMigrationStepJob::class);
});
