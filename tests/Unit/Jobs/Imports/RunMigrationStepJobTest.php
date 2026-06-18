<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Imports\RunMigrationStepJobTest;

use App\Modules\Imports\Jobs\RunMigrationStepJob;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Imports\Services\Handlers\HandlerManifest;
use App\Modules\Imports\Services\StepOrchestrator;
use App\Modules\Imports\Services\StepRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('job runs step and dispatches next pending when succeeded', function () {
    // Use the manifest-bound registry — exercises the real registration path.
    $registry = $this->app->make(StepRegistry::class);
    foreach (HandlerManifest::all() as $cls) {
        expect($registry->has($cls::key()))->toBeTrue('expected '.$cls::key().' to be registered');
    }

    Bus::fake();

    [$migration, $first, $second] = seedTwoStepMigration();

    // eligibility_scan handler resolves cleanly (does nothing if no children); execute
    // marks first succeeded and dispatches a job for the second pending step.
    (new RunMigrationStepJob($first->id))->handle($this->app->make(StepOrchestrator::class));

    $first->refresh();
    expect($first->status)->toBe(ImportMigrationStep::STATUS_SUCCEEDED);

    // Verify a follow-up job was dispatched for the next pending step.
    Bus::assertDispatched(RunMigrationStepJob::class, function (RunMigrationStepJob $job) use ($second): bool {
        return $job->stepId === $second->id;
    });
});
test('job is noop when step id missing', function () {
    (new RunMigrationStepJob('does-not-exist'))->handle(
        $this->app->make(StepOrchestrator::class)
    );
    expect(true)->toBeTrue();
    // didn't throw
});
/**
 * @return array{0: ImportServerMigration, 1: ImportMigrationStep, 2: ImportMigrationStep}
 */
function seedTwoStepMigration(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
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
        'status' => ImportServerMigration::STATUS_PENDING,
    ]);
    $first = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => ImportMigrationStep::KEY_ELIGIBILITY_SCAN,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);
    $second = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 2,
        'step_key' => ImportMigrationStep::KEY_REVOKE_SSH_KEY,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    return [$migration, $first, $second];
}
