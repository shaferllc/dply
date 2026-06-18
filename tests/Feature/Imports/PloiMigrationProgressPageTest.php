<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\PloiMigrationProgressPageTest;

use App\Modules\Imports\Jobs\RunMigrationStepJob;
use App\Modules\Imports\Livewire\Ploi\MigrationProgress;
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

function seedMigration(?User $user = null): ImportServerMigration
{
    if ($user === null) {
        $user = User::factory()->create();
    }
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_xxx'],
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
        'status' => ImportSiteMigration::STATUS_PENDING,
        'source_snapshot' => [],
    ]);
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_PUSH_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_SUCCEEDED,
    ]);
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 2,
        'step_key' => ImportMigrationStep::KEY_ELIGIBILITY_SCAN,
        'status' => ImportMigrationStep::STATUS_SUCCEEDED,
    ]);
    ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 3,
        'step_key' => ImportMigrationStep::KEY_CLONE_REPO,
        'status' => ImportMigrationStep::STATUS_FAILED,
        'error_message' => 'needs SSH to the target dply server; landing in phase 3b.',
    ]);

    return $migration;
}
test('page renders steps and status pills', function () {
    $user = User::factory()->create();
    $migration = seedMigration($user);

    $response = $this->actingAs($user)->get(route('imports.ploi.migration.progress', $migration));

    $response->assertOk()
        ->assertSee('app.example.com')
        ->assertSee('Push ephemeral SSH key')
        ->assertSee('Clone repository to dply server')
        ->assertSee('needs SSH to the target dply server')
        ->assertSee('Server-level steps')
        ->assertSee('Retry');
});
test('retry failed step resets status and dispatches job', function () {
    Bus::fake();
    $user = User::factory()->create();
    $migration = seedMigration($user);

    $failedStep = $migration->steps()->where('status', ImportMigrationStep::STATUS_FAILED)->first();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('retryFailedStep', $failedStep->id)
        ->assertHasNoErrors();

    $failedStep->refresh();
    expect($failedStep->status)->toBe(ImportMigrationStep::STATUS_PENDING);
    expect($failedStep->error_message)->toBeNull();
    Bus::assertDispatched(RunMigrationStepJob::class, function (RunMigrationStepJob $job) use ($failedStep): bool {
        return $job->stepId === $failedStep->id;
    });
});
test('retry rejects non failed steps', function () {
    Bus::fake();
    $user = User::factory()->create();
    $migration = seedMigration($user);

    $succeededStep = $migration->steps()->where('status', ImportMigrationStep::STATUS_SUCCEEDED)->first();

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('retryFailedStep', $succeededStep->id);

    Bus::assertNotDispatched(RunMigrationStepJob::class);
});
test('begin cutover dispatches first cutover step when site ready', function () {
    Bus::fake();
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
        'status' => ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
        'source_snapshot' => [],
    ]);
    $cutoverStep = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $site->id,
        'sequence' => 50,
        'step_key' => ImportMigrationStep::KEY_CUTOVER_MAINTENANCE_ON,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('beginCutover', $site->id)
        ->assertHasNoErrors();

    Bus::assertDispatched(RunMigrationStepJob::class, function (RunMigrationStepJob $job) use ($cutoverStep): bool {
        return $job->stepId === $cutoverStep->id;
    });
});
test('begin cutover rejects site not ready', function () {
    Bus::fake();
    $user = User::factory()->create();
    $migration = seedMigration($user);
    $site = $migration->siteMigrations()->first();
    expect($site)->not->toBeNull();
    $this->assertNotSame(ImportSiteMigration::STATUS_READY_FOR_CUTOVER, $site->status);

    Livewire::actingAs($user)
        ->test(MigrationProgress::class, ['migration' => $migration])
        ->call('beginCutover', $site->id);

    Bus::assertNotDispatched(RunMigrationStepJob::class);
});
test('page 403 for other org members', function () {
    $owner = User::factory()->create();
    $migration = seedMigration($owner);

    $stranger = User::factory()->create();
    $strangerOrg = Organization::factory()->create();
    $strangerOrg->users()->attach($stranger->id, ['role' => 'owner']);
    session(['current_organization_id' => $strangerOrg->id]);

    $this->actingAs($stranger)
        ->get(route('imports.ploi.migration.progress', $migration))
        ->assertForbidden();
});
