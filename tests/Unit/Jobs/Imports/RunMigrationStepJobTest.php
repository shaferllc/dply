<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Imports;

use App\Jobs\Imports\RunMigrationStepJob;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Imports\Handlers\HandlerManifest;
use App\Services\Imports\StepRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RunMigrationStepJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_runs_step_and_dispatches_next_pending_when_succeeded(): void
    {
        // Use the manifest-bound registry — exercises the real registration path.
        $registry = $this->app->make(StepRegistry::class);
        foreach (HandlerManifest::all() as $cls) {
            $this->assertTrue($registry->has($cls::key()), 'expected '.$cls::key().' to be registered');
        }

        Bus::fake();

        [$migration, $first, $second] = $this->seedTwoStepMigration();

        // eligibility_scan handler resolves cleanly (does nothing if no children); execute
        // marks first succeeded and dispatches a job for the second pending step.
        (new RunMigrationStepJob($first->id))->handle($this->app->make(\App\Services\Imports\StepOrchestrator::class));

        $first->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_SUCCEEDED, $first->status);

        // Verify a follow-up job was dispatched for the next pending step.
        Bus::assertDispatched(RunMigrationStepJob::class, function (RunMigrationStepJob $job) use ($second): bool {
            return $job->stepId === $second->id;
        });
    }

    public function test_job_is_noop_when_step_id_missing(): void
    {
        (new RunMigrationStepJob('does-not-exist'))->handle(
            $this->app->make(\App\Services\Imports\StepOrchestrator::class)
        );
        $this->assertTrue(true); // didn't throw
    }

    /**
     * @return array{0: ImportServerMigration, 1: ImportMigrationStep, 2: ImportMigrationStep}
     */
    protected function seedTwoStepMigration(): array
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
}
