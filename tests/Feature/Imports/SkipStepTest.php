<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\SkipStepTest;
use App\Jobs\Imports\RunMigrationStepJob;
use App\Livewire\Imports\Ploi\MigrationProgress;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{0: User, 1: ImportServerMigration, 2: ImportSiteMigration}
 */
function seedFailedStep(string $stepKey): array
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
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 10,
        'step_key' => $stepKey,
        'status' => ImportMigrationStep::STATUS_FAILED,
        'error_message' => 'transient failure',
    ]);

    // Next pending step so we can verify it gets dispatched.
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 11,
        'step_key' => ImportMigrationStep::KEY_SETUP_SSL,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    return [$user, $migration, $site];
}
test('skip marks skippable step and dispatches next', function () {
    Bus::fake();
    [$user, $migration] = seedFailedStep(ImportMigrationStep::KEY_RECREATE_CRONS);
    $failed = $migration->steps->where('step_key', ImportMigrationStep::KEY_RECREATE_CRONS)->first();
    $nextPending = $migration->steps->where('step_key', ImportMigrationStep::KEY_SETUP_SSL)->first();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('skipFailedStep', $failed->id)
        ->assertHasNoErrors();

    $failed->refresh();
    expect($failed->status)->toBe(ImportMigrationStep::STATUS_SKIPPED);
    $this->assertStringContainsString('Skipped by user', $failed->error_message);
    expect($failed->finished_at)->not->toBeNull();

    Bus::assertDispatched(RunMigrationStepJob::class, function (RunMigrationStepJob $job) use ($nextPending): bool {
        return $job->stepId === $nextPending->id;
    });
});
test('skip rejects non skippable step keys', function () {
    Bus::fake();

    // clone_repo, restore_database, etc. shouldn't be skippable.
    foreach ([
        ImportMigrationStep::KEY_CLONE_REPO,
        ImportMigrationStep::KEY_RESTORE_DB,
        ImportMigrationStep::KEY_COPY_ENV,
        ImportMigrationStep::KEY_DUMP_DB,
    ] as $protectedKey) {
        [$user, $migration] = seedFailedStep($protectedKey);
        $failed = $migration->steps->where('step_key', $protectedKey)->first();

        Livewire::actingAs($user)
            ->test(MigrationProgress::class, ['migration' => $migration])
            ->call('skipFailedStep', $failed->id);

        expect($failed->fresh()->status)->toBe(ImportMigrationStep::STATUS_FAILED, $protectedKey.' should not be skippable');
    }
    Bus::assertNotDispatched(RunMigrationStepJob::class);
});
test('skip rejects step not in failed state', function () {
    Bus::fake();
    [$user, $migration] = seedFailedStep(ImportMigrationStep::KEY_RECREATE_CRONS);
    $failed = $migration->steps->where('step_key', ImportMigrationStep::KEY_RECREATE_CRONS)->first();
    $failed->status = ImportMigrationStep::STATUS_SUCCEEDED;
    $failed->save();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('skipFailedStep', $failed->id);

    expect($failed->fresh()->status)->toBe(ImportMigrationStep::STATUS_SUCCEEDED);
    Bus::assertNotDispatched(RunMigrationStepJob::class);
});
test('progress page renders skip button for skippable failed steps only', function () {
    [$user, $migration] = seedFailedStep(ImportMigrationStep::KEY_RECREATE_CRONS);

    $this->actingAs($user)
        ->get(route('imports.ploi.migration.progress', $migration))
        ->assertOk()
        ->assertSee('Recreate cron jobs')
        ->assertSee('Skip');

    // Switch the failed step to a non-skippable key (clone_repo).
    $failed = $migration->steps->where('step_key', ImportMigrationStep::KEY_RECREATE_CRONS)->first();
    $failed->step_key = ImportMigrationStep::KEY_CLONE_REPO;
    $failed->save();

    // Skip button should disappear; Retry stays.
    $response = $this->actingAs($user)
        ->get(route('imports.ploi.migration.progress', $migration));
    $response->assertOk()->assertSee('Retry');

    // Count of "Skip" should be zero — only the Retry button for the now-clone_repo step.
    $this->assertStringNotContainsString('skipFailedStep', $response->getContent());
});
