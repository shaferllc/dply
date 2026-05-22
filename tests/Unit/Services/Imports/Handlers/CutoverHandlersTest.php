<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\CutoverHandlersTest;

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
use Tests\Support\Imports\FakeSourceSshConnectionFactory;
use Tests\Support\Imports\FakeSshConnectionFactory;
use Tests\Support\Imports\RecordingShell;

uses(RefreshDatabase::class);
/**
 * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: ImportServerMigration, 3: Site, 4: Server}
 */
function seedFixture(string $stepKey, string $sslStrategy = ImportSiteMigration::SSL_CLEAN): array
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
test('maintenance on enables via api and transitions status', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/maintenance' => Http::response('', 204),
    ]);
    [$step, $child, $migration] = seedFixture(ImportMigrationStep::KEY_CUTOVER_MAINTENANCE_ON);

    (new CutoverMaintenanceOnHandler)->execute($step);

    expect($child->fresh()->status)->toBe(ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS);
    expect($child->fresh()->cutover_started_at)->not->toBeNull();
    expect($migration->fresh()->status)->toBe(ImportServerMigration::STATUS_CUTOVER_IN_PROGRESS);
    Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
        && $req->url() === 'https://ploi.io/api/servers/42/sites/100/maintenance');
});
test('db delta redumps and restores via both shells', function () {
    $dumpBytes = "INSERT INTO orders…\n";
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response([
            'data' => [['id' => 7, 'name' => 'acme_prod', 'user' => 'acme']],
        ], 200),
    ]);
    $ploi = new RecordingShell;
    $ploi->responses[] = '';
    // mysqldump
    $ploi->responses[] = base64_encode($dumpBytes);
    // base64 read
    $ploi->responses[] = '';

    // rm cleanup
    $dply = new RecordingShell;
    $dply->responses[] = 'restored';

    [$step, , , $site] = seedFixture(ImportMigrationStep::KEY_CUTOVER_DB_DELTA);

    (new CutoverDbDeltaHandler(
        new FakeSshConnectionFactory($dply),
        new FakeSourceSshConnectionFactory($ploi),
    ))->execute($step);

    // Both restore path on dply and dump path on ploi were exercised
    expect($dply->written)->toHaveCount(1);
    expect($dply->commands)->toHaveCount(1);
    $this->assertStringContainsString('mysql --defaults-extra-file=/root/.my.cnf', $dply->commands[0]);
    $this->assertStringContainsString(escapeshellarg('acme_app'), $dply->commands[0]);
    expect($ploi->commands)->toHaveCount(3);
    $this->assertStringContainsString('mysqldump', $ploi->commands[0]);

    $step->refresh();
    expect($step->result_data['database'])->toBe('acme_prod');
    expect($step->result_data['bytes'])->toBe(strlen($dumpBytes));
});
test('db delta skips when no database', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/databases*' => Http::response(['data' => []], 200),
    ]);

    [$step] = seedFixture(ImportMigrationStep::KEY_CUTOVER_DB_DELTA);
    (new CutoverDbDeltaHandler(
        new FakeSshConnectionFactory(new RecordingShell),
        new FakeSourceSshConnectionFactory(new RecordingShell),
    ))->execute($step);

    expect($step->fresh()->status)->toBe(ImportMigrationStep::STATUS_SKIPPED);
});
test('webhook swap deletes each ploi webhook', function () {
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

    [$step] = seedFixture(ImportMigrationStep::KEY_CUTOVER_WEBHOOK_SWAP);
    (new CutoverWebhookSwapHandler)->execute($step);

    $step->refresh();
    expect($step->result_data['webhooks_attempted'])->toBe(2);
    expect($step->result_data['webhooks_removed'])->toBe(2);
    expect($step->result_data['failures'])->toBe(0);

    Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
        && str_ends_with($req->url(), '/deploy-keys/11'));
    Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
        && str_ends_with($req->url(), '/deploy-keys/12'));
});
test('webhook swap tolerates individual failures', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/deploy-keys/11' => Http::response('forbidden', 403),
        'https://ploi.io/api/servers/42/sites/100/deploy-keys*' => Http::response([
            'data' => [['id' => 11, 'url' => 'https://ploi.io/wh/x']],
        ], 200),
    ]);

    [$step] = seedFixture(ImportMigrationStep::KEY_CUTOVER_WEBHOOK_SWAP);
    (new CutoverWebhookSwapHandler)->execute($step);

    $step->refresh();
    expect($step->result_data['webhooks_attempted'])->toBe(1);
    expect($step->result_data['webhooks_removed'])->toBe(0);
    expect($step->result_data['failures'])->toBe(1);
});
test('smoke test completes when dply header observed', function () {
    Bus::fake();
    Http::fake([
        'https://app.example.com/*' => Http::response('hello', 200, [
            'X-Dply-Migration' => 'cutover-verify',
        ]),
    ]);

    [$step, $child, $migration] = seedFixture(ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST, sslStrategy: ImportSiteMigration::SSL_CLEAN);
    (new CutoverSmokeTestHandler)->execute($step);

    expect($child->fresh()->status)->toBe(ImportSiteMigration::STATUS_COMPLETED);
    expect($child->fresh()->cutover_completed_at)->not->toBeNull();
    expect($migration->fresh()->status)->toBe(ImportServerMigration::STATUS_COMPLETED);

    // Clean SSL strategy: no SSL re-issuance is dispatched at smoke test time.
    Bus::assertNotDispatched(IssueSiteSslJob::class);
});
test('smoke test dispatches ssl issuance for gap strategy', function () {
    Bus::fake();
    Http::fake([
        'https://app.example.com/*' => Http::response('hello', 200, [
            'X-Dply-Migration' => 'cutover-verify',
        ]),
    ]);

    [$step, , , $site] = seedFixture(ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST, sslStrategy: ImportSiteMigration::SSL_GAP);
    (new CutoverSmokeTestHandler)->execute($step);

    Bus::assertDispatched(IssueSiteSslJob::class, function (IssueSiteSslJob $job) use ($site): bool {
        return $job->siteId === $site->id;
    });
    expect($step->fresh()->result_data['ssl_issuance_queued'])->toBeTrue();
});
test('smoke test keeps polling then fails when header never appears', function () {
    Http::fake([
        'https://app.example.com/*' => Http::response('hello', 200),
    ]);

    // POLL_ATTEMPTS / POLL_INTERVAL_SECONDS are referenced via static:: in the handler
    // so an anonymous subclass overriding the constants shrinks the budget. Avoids
    // a real 5-minute sleep inside the test.
    $handler = new class extends CutoverSmokeTestHandler
    {
        public const POLL_ATTEMPTS = 2;

        public const POLL_INTERVAL_SECONDS = 0;
    };

    [$step] = seedFixture(ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/Smoke test failed/');
    $handler->execute($step);
});
