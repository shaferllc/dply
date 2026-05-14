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
use App\Services\Imports\Handlers\SetupSslHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\Imports\FakePloiSshConnectionFactory;
use Tests\Support\Imports\FakeSshConnectionFactory;
use Tests\Support\Imports\RecordingShell;
use Tests\TestCase;

class SetupSslHandlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: ImportMigrationStep, 1: ImportSiteMigration, 2: ImportServerMigration, 3: Site, 4: Server, 5: User, 6: Organization}
     */
    protected function seedFixture(): array
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

    public function test_clean_strategy_when_dns_credential_present_dispatches_issuance(): void
    {
        Bus::fake();
        Http::fake();

        [$step, $child, , $site, , $user, $org] = $this->seedFixture();
        // Add a DNS-capable credential to enable clean strategy.
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'cloudflare',
            'credentials' => ['api_token' => 'cf_token'],
        ]);

        $handler = new SetupSslHandler(
            new FakeSshConnectionFactory(new RecordingShell()),
            new FakePloiSshConnectionFactory(new RecordingShell()),
        );
        $handler->execute($step);

        $child->refresh();
        $this->assertSame(ImportSiteMigration::SSL_CLEAN, $child->ssl_strategy);

        $site->refresh();
        $this->assertSame(Site::SSL_PENDING, $site->ssl_status);

        Bus::assertDispatched(IssueSiteSslJob::class, function (IssueSiteSslJob $job) use ($site): bool {
            return $job->siteId === $site->id;
        });
    }

    public function test_bridged_strategy_when_no_dns_but_valid_letsencrypt_cert_on_ploi(): void
    {
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

        $ploi = new RecordingShell();
        $ploi->responses[] = base64_encode("-----BEGIN CERTIFICATE-----\nFAKECERT\n-----END CERTIFICATE-----\n");
        $ploi->responses[] = base64_encode("-----BEGIN PRIVATE KEY-----\nFAKEKEY\n-----END PRIVATE KEY-----\n");

        $dply = new RecordingShell();
        $dply->responses[] = ''; // mkdir
        $dply->responses[] = ''; // chmod privkey
        $dply->responses[] = ''; // chmod fullchain

        [$step, $child, , $site] = $this->seedFixture();

        $handler = new SetupSslHandler(
            new FakeSshConnectionFactory($dply),
            new FakePloiSshConnectionFactory($ploi),
        );
        $handler->execute($step);

        $child->refresh();
        $this->assertSame(ImportSiteMigration::SSL_BRIDGED, $child->ssl_strategy);
        $this->assertSame(Site::SSL_ACTIVE, $site->fresh()->ssl_status);

        // Both files transferred via putFile
        $this->assertCount(2, $dply->written);
        $this->assertArrayHasKey('/etc/letsencrypt/live/app.example.com/fullchain.pem', $dply->written);
        $this->assertArrayHasKey('/etc/letsencrypt/live/app.example.com/privkey.pem', $dply->written);
        $this->assertStringContainsString('FAKECERT', $dply->written['/etc/letsencrypt/live/app.example.com/fullchain.pem']);

        Bus::assertNotDispatched(IssueSiteSslJob::class);
    }

    public function test_gap_strategy_when_no_dns_and_no_usable_cert(): void
    {
        Bus::fake();
        Http::fake([
            'https://ploi.io/api/servers/42/sites/100/certificates' => Http::response(['data' => []], 200),
        ]);

        [$step, $child, , $site] = $this->seedFixture();

        $handler = new SetupSslHandler(
            new FakeSshConnectionFactory(new RecordingShell()),
            new FakePloiSshConnectionFactory(new RecordingShell()),
        );
        $handler->execute($step);

        $child->refresh();
        $this->assertSame(ImportSiteMigration::SSL_GAP, $child->ssl_strategy);
        $this->assertSame(Site::SSL_NONE, $site->fresh()->ssl_status);

        $step->refresh();
        $this->assertStringContainsString('30–120s', $step->result_data['note']);

        Bus::assertNotDispatched(IssueSiteSslJob::class);
    }

    public function test_gap_strategy_when_cert_expiring_within_seven_days(): void
    {
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

        [$step, $child] = $this->seedFixture();
        $handler = new SetupSslHandler(
            new FakeSshConnectionFactory(new RecordingShell()),
            new FakePloiSshConnectionFactory(new RecordingShell()),
        );
        $handler->execute($step);

        $this->assertSame(ImportSiteMigration::SSL_GAP, $child->fresh()->ssl_strategy);
    }
}
