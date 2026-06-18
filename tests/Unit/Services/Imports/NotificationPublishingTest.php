<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\NotificationPublishingTest;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Imports\Services\Handlers\CutoverSmokeTestHandler;
use App\Modules\Imports\Services\MigrationPlanner;
use App\Modules\Imports\Services\StepOrchestrator;
use App\Modules\Imports\Services\StepRegistry;
use App\Modules\Notifications\Services\NotificationEventRegistry;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: ImportServerMigration, 3: ImportSiteMigration, 4: Site}
 */
function seedMigration(string $childStatus = ImportSiteMigration::STATUS_STAGING): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'ploi',
    ]);
    $target = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);
    $site = Site::factory()->create([
        'server_id' => $target->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'acme-app',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
    $migration = ImportServerMigration::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'source' => 'ploi',
        'source_server_id' => 42,
        'target_server_id' => $target->id,
        'status' => ImportServerMigration::STATUS_STAGING,
    ]);
    $child = ImportSiteMigration::create([
        'import_server_migration_id' => $migration->id,
        'source' => 'ploi',
        'source_site_id' => 100,
        'domain' => 'app.example.com',
        'site_type' => 'laravel',
        'status' => $childStatus,
        'source_snapshot' => [],
        'target_site_id' => $site->id,
    ]);

    return [$user, $org, $migration, $child, $site];
}
test('step failed publishes notification event', function () {
    [, , $migration] = seedMigration();
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'sequence' => 1,
        'step_key' => 'totally_unknown_step', // forces failure via no-handler
        'status' => ImportMigrationStep::STATUS_PENDING,
    ]);

    $orchestrator = new StepOrchestrator(
        new StepRegistry,
        $this->app->make(NotificationPublisher::class),
    );
    $orchestrator->executeStep($step);

    expect($step->fresh()->status)->toBe(ImportMigrationStep::STATUS_FAILED);
    $this->assertDatabaseHas('notification_events', [
        'event_key' => 'import.migration.step_failed',
        'subject_type' => ImportServerMigration::class,
        'subject_id' => $migration->id,
    ]);
    $event = NotificationEvent::query()->where('event_key', 'import.migration.step_failed')->first();
    expect($event)->not->toBeNull();
    expect($event->supports_email)->toBeTrue();
    expect($event->severity)->toBe('warning');
});
test('cutover ready publishes when site finishes staging', function () {
    // Synthesize: child in STAGING, all STAGING_STEPS succeeded; the next succeeded step
    // (any staging-tier) triggers maybeMarkSiteReady which publishes.
    [, , $migration, $child] = seedMigration(childStatus: ImportSiteMigration::STATUS_STAGING);

    // All staging steps already succeeded — the orchestrator only needs to evaluate after
    // one more succeeds. We synthesize that final-success path by inserting a freeze_snapshot
    // step succeeded, then running it through markSucceeded → maybeMarkSiteReady manually.
    foreach (MigrationPlanner::STAGING_STEPS as $key) {
        ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 20,
            'step_key' => $key,
            'status' => ImportMigrationStep::STATUS_SUCCEEDED,
        ]);
    }

    // Pick the last staging step and re-run executeStep on it — handler is a no-op for our
    // purposes; what matters is that maybeAdvanceMigration → maybeMarkSiteReady fires.
    $tailStep = ImportMigrationStep::query()
        ->where('import_site_migration_id', $child->id)
        ->where('step_key', MigrationPlanner::STAGING_STEPS[0])
        ->first();

    // Reset to PENDING so orchestrator runs it.
    $tailStep->status = ImportMigrationStep::STATUS_PENDING;
    $tailStep->save();

    $registry = new StepRegistry;
    $registry->register($tailStep->step_key, NoOpHandler::class);

    (new StepOrchestrator($registry, $this->app->make(NotificationPublisher::class)))
        ->executeStep($tailStep);

    expect($child->fresh()->status)->toBe(ImportSiteMigration::STATUS_READY_FOR_CUTOVER);
    $this->assertDatabaseHas('notification_events', [
        'event_key' => 'import.migration.cutover_ready',
        'subject_type' => ImportServerMigration::class,
        'subject_id' => $migration->id,
    ]);
});
test('smoke test success publishes cutover complete', function () {
    Http::fake([
        'https://app.example.com/*' => Http::response('hello', 200, [
            'X-Dply-Migration' => 'cutover-verify',
        ]),
    ]);
    [, , $migration, $child] = seedMigration(childStatus: ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS);
    $child->ssl_strategy = ImportSiteMigration::SSL_CLEAN;
    $child->save();

    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $child->id,
        'sequence' => 70,
        'step_key' => ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    (new CutoverSmokeTestHandler($this->app->make(NotificationPublisher::class)))->execute($step);

    expect($child->fresh()->status)->toBe(ImportSiteMigration::STATUS_COMPLETED);
    $this->assertDatabaseHas('notification_events', [
        'event_key' => 'import.migration.cutover_complete',
        'subject_type' => ImportServerMigration::class,
        'subject_id' => $migration->id,
    ]);
});
test('notification event supports email for import keys', function () {
    $registry = $this->app->make(NotificationEventRegistry::class);

    $definition = $registry->definition('import.migration.cutover_ready');
    expect($definition['supports_email'])->toBeTrue();
    expect($definition['severity'])->toBe('warning');

    $definition = $registry->definition('import.migration.cutover_complete');
    expect($definition['supports_email'])->toBeTrue();
    expect($definition['severity'])->toBe('info');

    $definition = $registry->definition('server.ssh_login');
    expect($definition['supports_email'])->toBeFalse('Non-import events stay email-off');
});
