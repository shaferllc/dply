<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Handlers\SetupSslHandlerTest;

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
use App\Modules\Imports\Services\Handlers\SetupSslHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\Imports\FakeSourceSshConnectionFactory;
use Tests\Support\Imports\FakeSshConnectionFactory;
use Tests\Support\Imports\RecordingShell;

uses(RefreshDatabase::class);
/**
 * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: ImportServerMigration, 3: Site, 4: Server, 5: User, 6: Organization}
 */
function seedFixture(): array
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
        'status' => ImportSiteMigration::STATUS_STAGING,
        'source_snapshot' => [],
        'target_site_id' => $site->id,
    ]);
    $step = ImportMigrationStep::create([
        'import_server_migration_id' => $migration->id,
        'import_site_migration_id' => $child->id,
        'sequence' => 18,
        'step_key' => ImportMigrationStep::KEY_SETUP_SSL,
        'status' => ImportMigrationStep::STATUS_RUNNING,
    ]);

    return [$step, $child, $migration, $site, $target, $user, $org];
}
test('clean strategy when dns credential present dispatches issuance', function () {
    Bus::fake();
    Http::fake();

    [$step, $child, , $site, , $user, $org] = seedFixture();

    // Add a DNS-capable credential to enable clean strategy.
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'cf_token'],
    ]);

    $handler = new SetupSslHandler(
        new FakeSshConnectionFactory(new RecordingShell),
        new FakeSourceSshConnectionFactory(new RecordingShell),
    );
    $handler->execute($step);

    $child->refresh();
    expect($child->ssl_strategy)->toBe(ImportSiteMigration::SSL_CLEAN);

    $site->refresh();
    expect($site->ssl_status)->toBe(Site::SSL_PENDING);

    Bus::assertDispatched(IssueSiteSslJob::class, function (IssueSiteSslJob $job) use ($site): bool {
        return $job->siteId === $site->id;
    });
});
test('bridged strategy when no dns but valid letsencrypt cert on ploi', function () {
    Bus::fake();
    $futureCutoff = now()->addDays(30)->toIso8601String();
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/certificates' => Http::response([
            'data' => [[
                'id' => 1,
                'type' => 'letsencrypt',
                'domain' => 'app.example.com',
                'expires_at' => $futureCutoff,
                'status' => 'active',
            ]],
        ], 200),
    ]);

    $ploi = new RecordingShell;
    $ploi->responses[] = base64_encode("-----BEGIN CERTIFICATE-----\nFAKECERT\n-----END CERTIFICATE-----\n");
    $ploi->responses[] = base64_encode("-----BEGIN PRIVATE KEY-----\nFAKEKEY\n-----END PRIVATE KEY-----\n");

    $dply = new RecordingShell;
    $dply->responses[] = '';
    // mkdir
    $dply->responses[] = '';
    // chmod privkey
    $dply->responses[] = '';

    // chmod fullchain
    [$step, $child, , $site] = seedFixture();

    $handler = new SetupSslHandler(
        new FakeSshConnectionFactory($dply),
        new FakeSourceSshConnectionFactory($ploi),
    );
    $handler->execute($step);

    $child->refresh();
    expect($child->ssl_strategy)->toBe(ImportSiteMigration::SSL_BRIDGED);
    expect($site->fresh()->ssl_status)->toBe(Site::SSL_ACTIVE);

    // Both files transferred via putFile
    expect($dply->written)->toHaveCount(2);
    expect($dply->written)->toHaveKey('/etc/letsencrypt/live/app.example.com/fullchain.pem');
    expect($dply->written)->toHaveKey('/etc/letsencrypt/live/app.example.com/privkey.pem');
    $this->assertStringContainsString('FAKECERT', $dply->written['/etc/letsencrypt/live/app.example.com/fullchain.pem']);

    Bus::assertNotDispatched(IssueSiteSslJob::class);
});
test('gap strategy when no dns and no usable cert', function () {
    Bus::fake();
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/certificates' => Http::response(['data' => []], 200),
    ]);

    [$step, $child, , $site] = seedFixture();

    $handler = new SetupSslHandler(
        new FakeSshConnectionFactory(new RecordingShell),
        new FakeSourceSshConnectionFactory(new RecordingShell),
    );
    $handler->execute($step);

    $child->refresh();
    expect($child->ssl_strategy)->toBe(ImportSiteMigration::SSL_GAP);
    expect($site->fresh()->ssl_status)->toBe(Site::SSL_NONE);

    $step->refresh();
    $this->assertStringContainsString('30–120s', $step->result_data['note']);

    Bus::assertNotDispatched(IssueSiteSslJob::class);
});
test('gap strategy when cert expiring within seven days', function () {
    Bus::fake();
    Http::fake([
        'https://ploi.io/api/servers/42/sites/100/certificates' => Http::response([
            'data' => [[
                'id' => 1,
                'type' => 'letsencrypt',
                'domain' => 'app.example.com',
                'expires_at' => now()->addDays(3)->toIso8601String(),
                'status' => 'active',
            ]],
        ], 200),
    ]);

    [$step, $child] = seedFixture();
    $handler = new SetupSslHandler(
        new FakeSshConnectionFactory(new RecordingShell),
        new FakeSourceSshConnectionFactory(new RecordingShell),
    );
    $handler->execute($step);

    expect($child->fresh()->ssl_strategy)->toBe(ImportSiteMigration::SSL_GAP);
});
