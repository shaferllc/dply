<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Imports\StepHandler;
use App\Services\Imports\StepOrchestrator;
use App\Services\Imports\StepRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StepOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_marks_succeeded_when_handler_returns_cleanly(): void
    {
        [$migration, $step] = $this->seedSingleStep('freeze_snapshot');

        $registry = new StepRegistry();
        $registry->register('freeze_snapshot', InlineSucceedingHandler::class);

        (new StepOrchestrator($registry))->executeStep($step);

        $step->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_SUCCEEDED, $step->status);
        $this->assertSame(1, $step->attempts);
        $this->assertNotNull($step->started_at);
        $this->assertNotNull($step->finished_at);

        $migration->refresh();
        $this->assertSame(ImportServerMigration::STATUS_STAGING, $migration->status);
        $this->assertNotNull($migration->started_at);
    }

    public function test_execute_marks_failed_and_records_message_when_handler_throws(): void
    {
        [, $step] = $this->seedSingleStep('freeze_snapshot');

        $registry = new StepRegistry();
        $registry->register('freeze_snapshot', InlineThrowingHandler::class);

        (new StepOrchestrator($registry))->executeStep($step);

        $step->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_FAILED, $step->status);
        $this->assertStringContainsString('boom', $step->error_message);
    }

    public function test_execute_is_a_noop_for_terminal_steps(): void
    {
        [, $step] = $this->seedSingleStep('freeze_snapshot');
        $step->status = ImportMigrationStep::STATUS_SUCCEEDED;
        $step->save();

        $registry = new StepRegistry();
        $registry->register('freeze_snapshot', InlineThrowingHandler::class);

        (new StepOrchestrator($registry))->executeStep($step);

        $step->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_SUCCEEDED, $step->status);
        $this->assertSame(0, $step->attempts);
    }

    public function test_unknown_step_key_marks_failed_with_clear_message(): void
    {
        [, $step] = $this->seedSingleStep('totally_unknown');

        (new StepOrchestrator(new StepRegistry()))->executeStep($step);

        $step->refresh();
        $this->assertSame(ImportMigrationStep::STATUS_FAILED, $step->status);
        $this->assertStringContainsString('No handler registered', $step->error_message);
    }

    public function test_next_step_skips_cutover_steps(): void
    {
        $migration = $this->seedMigration();
        // sequence 1: freeze_snapshot (staging)
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'sequence' => 1,
            'step_key' => ImportMigrationStep::KEY_FREEZE_SNAPSHOT,
            'status' => ImportMigrationStep::STATUS_SUCCEEDED,
        ]);
        // sequence 2: cutover_dns_swap — should NOT be picked
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'sequence' => 2,
            'step_key' => ImportMigrationStep::KEY_CUTOVER_DNS_SWAP,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);
        // sequence 3: revoke_ssh_key — staging-tier, runnable
        $revoke = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'sequence' => 3,
            'step_key' => ImportMigrationStep::KEY_REVOKE_SSH_KEY,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);

        $next = (new StepOrchestrator(new StepRegistry()))->nextStep($migration);

        $this->assertNotNull($next);
        $this->assertSame($revoke->id, $next->id);
    }

    /**
     * @return array{0: ImportServerMigration, 1: ImportMigrationStep}
     */
    protected function seedSingleStep(string $stepKey): array
    {
        $migration = $this->seedMigration();
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'sequence' => 1,
            'step_key' => $stepKey,
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);

        return [$migration, $step];
    }

    protected function seedMigration(): ImportServerMigration
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_xxx'],
        ]);

        return ImportServerMigration::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider_credential_id' => $credential->id,
            'source' => 'ploi',
            'source_server_id' => 42,
            'status' => ImportServerMigration::STATUS_PENDING,
        ]);
    }
}

final class InlineSucceedingHandler implements StepHandler
{
    public static function key(): string
    {
        return 'freeze_snapshot';
    }

    public function execute(ImportMigrationStep $step): void {}
}

final class InlineThrowingHandler implements StepHandler
{
    public static function key(): string
    {
        return 'freeze_snapshot';
    }

    public function execute(ImportMigrationStep $step): void
    {
        throw new RuntimeException('boom');
    }
}
