<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers;

use App\Jobs\IssueSiteSslJob;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Organization;
use App\Models\PloiServer;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Imports\Handlers\CutoverDbDeltaHandler;
use App\Services\Imports\Handlers\CutoverMaintenanceOnHandler;
use App\Services\Imports\Handlers\CutoverSmokeTestHandler;
use App\Services\Imports\Handlers\CutoverWebhookSwapHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\Imports\FakePloiSshConnectionFactory;
use Tests\Support\Imports\FakeSshConnectionFactory;
use Tests\Support\Imports\RecordingShell;
use Tests\TestCase;

class CutoverHandlersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: ImportServerMigration, 3: Site, 4: Server}
     */
    protected function seedFixture(string $stepKey, string $sslStrategy = ImportSiteMigration::SSL_CLEAN): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_token'],
        ]);
        PloiServer::create([
            'provider_credential_id' => $credential->id,
            'source_id' => 42,
            'name' => 'src',
            'ip_address' => '203.0.113.10',
            'provider_label' => 'digital-ocean',
            'server_type' => null,
            'php_versions' => [],
            'status' => 'active',
            'last_synced_at' => now(),
            'removed_from_source' => false,
            'source_snapshot' => null,
        ]);
        $target = Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'status' => Server::STATUS_READY,
            'ip_address' => '198.51.100.50',
        ]);
        $site = Site::factory()->create([
            'server_id' => $target->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
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
            'ssh_key_private_encrypted' => 'unused-here',
        ]);
        $child = ImportSiteMigration::create([
            'import_server_migration_id' => $migration->id,
            'source' => 'ploi',
            'source_site_id' => 100,
            'domain' => 'app.example.com',
            'site_type' => 'laravel',
            'status' => ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
            'source_snapshot' => [],
            'target_site_id' => $site->id,
            'ssl_strategy' => $sslStrategy,
        ]);
        $step = ImportMigrationStep::create([
            'import_server_migration_id' => $migration->id,
            'import_site_migration_id' => $child->id,
            'sequence' => 50,
            'step_key' => $stepKey,
            'status' => ImportMigrationStep::STATUS_RUNNING,
        ]);

        return [$step, $child, $migration, $site, $target];
    }

    public function test_maintenance_on_enables_via_api_and_transitions_status(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/maintenance' => Http::response('', 204),
        ]);
        [$step, $child, $migration] = $this->seedFixture(ImportMigrationStep::KEY_CUTOVER_MAINTENANCE_ON);

        (new CutoverMaintenanceOnHandler())->execute($step);

        $this->assertSame(ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS, $child->fresh()->status);
        $this->assertNotNull($child->fresh()->cutover_started_at);
        $this->assertSame(ImportServerMigration::STATUS_CUTOVER_IN_PROGRESS, $migration->fresh()->status);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
            && $req->url() === 'https://ploi.io/api/servers/42/sites/100/maintenance');
    }

    public function test_db_delta_redumps_and_restores_via_both_shells(): void
    {
        $dumpBytes = "INSERT INTO orders…\n";
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response([
                'data' => [['id' => 7, 'name' => 'acme_prod', 'user' => 'acme']],
            ], 200),
        ]);
        $ploi = new RecordingShell();
        $ploi->responses[] = '';                      // mysqldump
        $ploi->responses[] = base64_encode($dumpBytes); // base64 read
        $ploi->responses[] = '';                      // rm cleanup

        $dply = new RecordingShell();
        $dply->responses[] = 'restored';

        [$step, , , $site] = $this->seedFixture(ImportMigrationStep::KEY_CUTOVER_DB_DELTA);

        (new CutoverDbDeltaHandler(
            new FakeSshConnectionFactory($dply),
            new FakePloiSshConnectionFactory($ploi),
        ))->execute($step);

        // Both restore path on dply and dump path on ploi were exercised
        $this->assertCount(1, $dply->written);
        $this->assertCount(1, $dply->commands);
        $this->assertStringContainsString('mysql --defaults-extra-file=/root/.my.cnf', $dply->commands[0]);
        $this->assertStringContainsString(escapeshellarg('acme_app'), $dply->commands[0]);
        $this->assertCount(3, $ploi->commands);
        $this->assertStringContainsString('mysqldump', $ploi->commands[0]);

        $step->refresh();
        $this->assertSame('acme_prod', $step->result_data['database']);
        $this->assertSame(strlen($dumpBytes), $step->result_data['bytes']);
    }

    public function test_db_delta_skips_when_no_database(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response(['data' => []], 200),
        ]);

        [$step] = $this->seedFixture(ImportMigrationStep::KEY_CUTOVER_DB_DELTA);
        (new CutoverDbDeltaHandler(
            new FakeSshConnectionFactory(new RecordingShell()),
            new FakePloiSshConnectionFactory(new RecordingShell()),
        ))->execute($step);

        $this->assertSame(ImportMigrationStep::STATUS_SKIPPED, $step->fresh()->status);
    }

    public function test_webhook_swap_deletes_each_ploi_webhook(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/deploy-keys/11' => Http::response('', 204),
            'https://ploi.io/api/servers/42/sites/100/deploy-keys/12' => Http::response('', 204),
            'https://ploi.io/api/servers/42/sites/100/deploy-keys*' => Http::response([
                'data' => [
                    ['id' => 11, 'url' => 'https://ploi.io/wh/x'],
                    ['id' => 12, 'url' => 'https://ploi.io/wh/y'],
                ],
            ], 200),
        ]);

        [$step] = $this->seedFixture(ImportMigrationStep::KEY_CUTOVER_WEBHOOK_SWAP);
        (new CutoverWebhookSwapHandler())->execute($step);

        $step->refresh();
        $this->assertSame(2, $step->result_data['webhooks_attempted']);
        $this->assertSame(2, $step->result_data['webhooks_removed']);
        $this->assertSame(0, $step->result_data['failures']);

        Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
            && str_ends_with($req->url(), '/deploy-keys/11'));
        Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
            && str_ends_with($req->url(), '/deploy-keys/12'));
    }

    public function test_webhook_swap_tolerates_individual_failures(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/deploy-keys/11' => Http::response('forbidden', 403),
            'https://ploi.io/api/servers/42/sites/100/deploy-keys*' => Http::response([
                'data' => [['id' => 11, 'url' => 'https://ploi.io/wh/x']],
            ], 200),
        ]);

        [$step] = $this->seedFixture(ImportMigrationStep::KEY_CUTOVER_WEBHOOK_SWAP);
        (new CutoverWebhookSwapHandler())->execute($step);

        $step->refresh();
        $this->assertSame(1, $step->result_data['webhooks_attempted']);
        $this->assertSame(0, $step->result_data['webhooks_removed']);
        $this->assertSame(1, $step->result_data['failures']);
    }

    public function test_smoke_test_completes_when_dply_header_observed(): void
    {
        Bus::fake();
        Http::fake([
            'https://app.example.com/*' => Http::response('hello', 200, [
                'X-Dply-Migration' => 'cutover-verify',
            ]),
        ]);

        [$step, $child, $migration] = $this->seedFixture(
            ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST,
            sslStrategy: ImportSiteMigration::SSL_CLEAN,
        );
        (new CutoverSmokeTestHandler())->execute($step);

        $this->assertSame(ImportSiteMigration::STATUS_COMPLETED, $child->fresh()->status);
        $this->assertNotNull($child->fresh()->cutover_completed_at);
        $this->assertSame(ImportServerMigration::STATUS_COMPLETED, $migration->fresh()->status);

        // Clean SSL strategy: no SSL re-issuance is dispatched at smoke test time.
        Bus::assertNotDispatched(IssueSiteSslJob::class);
    }

    public function test_smoke_test_dispatches_ssl_issuance_for_gap_strategy(): void
    {
        Bus::fake();
        Http::fake([
            'https://app.example.com/*' => Http::response('hello', 200, [
                'X-Dply-Migration' => 'cutover-verify',
            ]),
        ]);

        [$step, , , $site] = $this->seedFixture(
            ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST,
            sslStrategy: ImportSiteMigration::SSL_GAP,
        );
        (new CutoverSmokeTestHandler())->execute($step);

        Bus::assertDispatched(IssueSiteSslJob::class, function (IssueSiteSslJob $job) use ($site): bool {
            return $job->siteId === $site->id;
        });
        $this->assertTrue($step->fresh()->result_data['ssl_issuance_queued']);
    }

    public function test_smoke_test_keeps_polling_then_fails_when_header_never_appears(): void
    {
        Http::fake([
            'https://app.example.com/*' => Http::response('hello', 200),
        ]);
        // POLL_ATTEMPTS / POLL_INTERVAL_SECONDS are referenced via static:: in the handler
        // so an anonymous subclass overriding the constants shrinks the budget. Avoids
        // a real 5-minute sleep inside the test.
        $handler = new class extends CutoverSmokeTestHandler {
            public const POLL_ATTEMPTS = 2;

            public const POLL_INTERVAL_SECONDS = 0;
        };

        [$step] = $this->seedFixture(ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Smoke test failed/');
        $handler->execute($step);
    }
}
