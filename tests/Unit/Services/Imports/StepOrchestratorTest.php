<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\StepOrchestratorTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Imports\StepOrchestrator;
use App\Services\Imports\StepRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('execute marks succeeded when handler returns cleanly', function () {
    [$migration, $step] = seedSingleStep('freeze_snapshot');

    $registry = new StepRegistry;
    $registry->register('freeze_snapshot', InlineSucceedingHandler::class);

    (new StepOrchestrator($registry))->executeStep($step);

    $step->refresh();
    expect($step->status)->toBe(ImportMigrationStep::STATUS_SUCCEEDED);
    expect($step->attempts)->toBe(1);
    expect($step->started_at)->not->toBeNull();
    expect($step->finished_at)->not->toBeNull();

    $migration->refresh();
    expect($migration->status)->toBe(ImportServerMigration::STATUS_STAGING);
    expect($migration->started_at)->not->toBeNull();
});
test('execute marks failed and records message when handler throws', function () {
    [, $step] = seedSingleStep('freeze_snapshot');

    $registry = new StepRegistry;
    $registry->register('freeze_snapshot', InlineThrowingHandler::class);

    (new StepOrchestrator($registry))->executeStep($step);

    $step->refresh();
    expect($step->status)->toBe(ImportMigrationStep::STATUS_FAILED);
    $this->assertStringContainsString('boom', $step->error_message);
});
test('execute is a noop for terminal steps', function () {
    [, $step] = seedSingleStep('freeze_snapshot');
    $step->status = ImportMigrationStep::STATUS_SUCCEEDED;
    $step->save();

    $registry = new StepRegistry;
    $registry->register('freeze_snapshot', InlineThrowingHandler::class);

    (new StepOrchestrator($registry))->executeStep($step);

    $step->refresh();
    expect($step->status)->toBe(ImportMigrationStep::STATUS_SUCCEEDED);
    expect($step->attempts)->toBe(0);
});
test('unknown step key marks failed with clear message', function () {
    [, $step] = seedSingleStep('totally_unknown');

    (new StepOrchestrator(new StepRegistry))->executeStep($step);

    $step->refresh();
    expect($step->status)->toBe(ImportMigrationStep::STATUS_FAILED);
    $this->assertStringContainsString('No handler registered', $step->error_message);
});
test('next step skips cutover steps', function () {
    $migration = seedMigration();

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

    $next = (new StepOrchestrator(new StepRegistry))->nextStep($migration);

    expect($next)->not->toBeNull();
    expect($next->id)->toBe($revoke->id);
});
/**
 * @return array{0: ImportServerMigration, 1: ImportMigrationStep}
 */
function seedSingleStep(string $stepKey): array
{
    $migration = seedMigration();
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => $stepKey,
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    return [$migration, $step];
}
function seedMigration(): ImportServerMigration
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
