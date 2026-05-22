<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Imports\Handlers\CutoverSmokeTestHandler;
use App\Services\Imports\Handlers\EligibilityScanHandler;
use App\Services\Imports\Handlers\PushSshKeyHandler;
use App\Services\Imports\StepHandler;
use App\Services\Imports\StepOrchestrator;
use App\Services\Imports\StepRegistry;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NotificationPublishingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: ImportServerMigration, 3: ImportSiteMigration, 4: Site}
     */
    protected function seedMigration(string $childStatus = ImportSiteMigration::STATUS_STAGING): array
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

    public function test_step_failed_publishes_notification_event(): void
    {
        [, , $migration] = $this->seedMigration();
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'sequence' => 1,
            'step_key' => 'totally_unknown_step', // forces failure via no-handler
            'status' => ImportMigrationStep::STATUS_PENDING,
        ]);

        $orchestrator = new StepOrchestrator(
            new StepRegistry(),
            $this->app->make(NotificationPublisher::class),
        );
        $orchestrator->executeStep($step);

        $this->assertSame(ImportMigrationStep::STATUS_FAILED, $step->fresh()->status);
        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'import.migration.step_failed',
            'subject_type' => ImportServerMigration::class,
            'subject_id' => $migration->id,
        ]);
        $event = NotificationEvent::query()->where('event_key', 'import.migration.step_failed')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->supports_email);
        $this->assertSame('warning', $event->severity);
    }

    public function test_cutover_ready_publishes_when_site_finishes_staging(): void
    {
        // Synthesize: child in STAGING, all STAGING_STEPS succeeded; the next succeeded step
        // (any staging-tier) triggers maybeMarkSiteReady which publishes.
        [, , $migration, $child] = $this->seedMigration(childStatus: ImportSiteMigration::STATUS_STAGING);

        // All staging steps already succeeded — the orchestrator only needs to evaluate after
        // one more succeeds. We synthesize that final-success path by inserting a freeze_snapshot
        // step succeeded, then running it through markSucceeded → maybeMarkSiteReady manually.
        foreach (\App\Services\Imports\MigrationPlanner::STAGING_STEPS as $key) {
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
            ->where('step_key', \App\Services\Imports\MigrationPlanner::STAGING_STEPS[0])
            ->first();
        // Reset to PENDING so orchestrator runs it.
        $tailStep->status = ImportMigrationStep::STATUS_PENDING;
        $tailStep->save();

        $registry = new StepRegistry();
        $registry->register($tailStep->step_key, NoOpHandler::class);

        (new StepOrchestrator($registry, $this->app->make(NotificationPublisher::class)))
            ->executeStep($tailStep);

        $this->assertSame(ImportSiteMigration::STATUS_READY_FOR_CUTOVER, $child->fresh()->status);
        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'import.migration.cutover_ready',
            'subject_type' => ImportServerMigration::class,
            'subject_id' => $migration->id,
        ]);
    }

    public function test_smoke_test_success_publishes_cutover_complete(): void
    {
        Http::fake([
            'https://app.example.com/*' => Http::response('hello', 200, [
                'X-Dply-Migration' => 'cutover-verify',
            ]),
        ]);
        [, , $migration, $child] = $this->seedMigration(
            childStatus: ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS,
        );
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

        $this->assertSame(ImportSiteMigration::STATUS_COMPLETED, $child->fresh()->status);
        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'import.migration.cutover_complete',
            'subject_type' => ImportServerMigration::class,
            'subject_id' => $migration->id,
        ]);
    }

    public function test_notification_event_supports_email_for_import_keys(): void
    {
        $registry = $this->app->make(\App\Services\Notifications\NotificationEventRegistry::class);

        $definition = $registry->definition('import.migration.cutover_ready');
        $this->assertTrue($definition['supports_email']);
        $this->assertSame('warning', $definition['severity']);

        $definition = $registry->definition('import.migration.cutover_complete');
        $this->assertTrue($definition['supports_email']);
        $this->assertSame('info', $definition['severity']);

        $definition = $registry->definition('server.ssh_login');
        $this->assertFalse($definition['supports_email'], 'Non-import events stay email-off');
    }
}

final class NoOpHandler implements StepHandler
{
    public static function key(): string
    {
        return 'freeze_snapshot';
    }

    public function execute(ImportMigrationStep $step): void {}
}
