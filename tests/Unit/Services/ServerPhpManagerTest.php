<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerPhpManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerPhpManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function makeServerWithMeta(array $meta = []): Server
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $user->organizations()->attach($org->id, ['role' => 'owner']);

        return Server::factory()->create([
            'organization_id' => $org->id,
            'meta' => $meta,
            'ip_address' => '203.0.113.10',
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'status' => Server::STATUS_READY,
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);
    }

    #[Test]
    public function it_selects_supported_versions_for_the_server_role(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
        ]);

        $manager = new ServerPhpManager;
        $supported = $manager->supportedVersions($server);

        $ids = array_column($supported, 'id');

        $this->assertContains('8.4', $ids);
        $this->assertContains('8.3', $ids);
        $this->assertContains('7.4', $ids);
        $this->assertNotContains('none', $ids);
    }

    #[Test]
    public function it_excludes_php_versions_when_the_server_role_does_not_support_php(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'plain',
        ]);

        $manager = new ServerPhpManager;

        $this->assertSame([], $manager->supportedVersions($server));
    }

    #[Test]
    public function it_normalizes_cached_installed_versions_and_defaults(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'default_php_version' => 'PHP 8.3',
            'php_new_site_default_version' => 'php8.2',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', 'php8.3', 'PHP 8.2', '8.2', 'garbage'],
                'detected_default_version' => 'php8.3',
            ],
        ]);

        $manager = new ServerPhpManager;
        $inventory = $manager->cachedInventory($server);
        $defaults = $manager->currentDefaults($server);

        $this->assertTrue($inventory['is_supported_environment']);
        $this->assertSame(['8.4', '8.3', '8.2'], array_column($inventory['installed_versions'], 'id'));
        $this->assertSame('8.3', $defaults['cli_default']);
        $this->assertSame('8.2', $defaults['new_site_default']);
        $this->assertSame('8.3', $inventory['detected_default_version']);
    }

    #[Test]
    public function it_falls_back_when_the_cached_cli_default_is_not_installed(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'default_php_version' => '8.1',
            'php_new_site_default_version' => '8.3',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
        ]);

        $manager = new ServerPhpManager;

        $this->assertSame([
            'cli_default' => '8.4',
            'new_site_default' => '8.3',
        ], $manager->currentDefaults($server));
    }

    #[Test]
    public function it_reconciles_fresh_inventory_using_remote_state_as_source_of_truth(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'default_php_version' => '8.2',
            'php_new_site_default_version' => '8.1',
            'php_inventory' => [
                'installed_versions' => ['8.2', '8.1'],
                'detected_default_version' => '8.2',
            ],
        ]);

        $manager = new ServerPhpManager;

        $reconciled = $manager->reconcileFreshInventory($server, [
            'installed_versions' => ['php8.4', '8.3'],
            'detected_default_version' => 'php8.4',
        ]);

        $this->assertSame(['8.4', '8.3'], $reconciled['php_inventory']['installed_versions']);
        $this->assertSame('8.4', $reconciled['php_inventory']['detected_default_version']);
        $this->assertSame('8.4', $reconciled['default_php_version']);
        $this->assertSame('8.4', $reconciled['php_new_site_default_version']);
    }

    #[Test]
    public function it_preserves_a_valid_user_new_site_default_when_inventory_disagrees_with_cached_metadata(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'default_php_version' => '8.2',
            'php_new_site_default_version' => '8.3',
            'php_inventory' => [
                'installed_versions' => ['8.2'],
                'detected_default_version' => '8.2',
            ],
        ]);

        $manager = new ServerPhpManager;

        $reconciled = $manager->reconcileFreshInventory($server, [
            'installed_versions' => ['8.4', '8.3'],
            'detected_default_version' => '8.4',
        ]);

        $this->assertSame('8.4', $reconciled['default_php_version']);
        $this->assertSame('8.3', $reconciled['php_new_site_default_version']);
    }

    #[Test]
    public function it_replaces_an_invalid_user_new_site_default_with_the_remote_default(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'default_php_version' => '8.3',
            'php_new_site_default_version' => '7.4',
            'php_inventory' => [
                'installed_versions' => ['8.3', '7.4'],
                'detected_default_version' => '8.4',
            ],
        ]);

        $manager = new ServerPhpManager;

        $reconciled = $manager->reconcileFreshInventory($server, [
            'installed_versions' => ['8.4', '8.3'],
            'detected_default_version' => '8.4',
        ]);

        $this->assertSame('8.4', $reconciled['php_new_site_default_version']);
    }

    #[Test]
    public function it_persists_the_refreshed_php_inventory_snapshot_defaults_and_timestamp(): void
    {
        CarbonImmutable::setTestNow('2026-03-31 12:00:00');

        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'default_php_version' => '8.2',
            'php_new_site_default_version' => '8.3',
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['php8.4', '8.3'],
                'detected_default_version' => 'php8.4',
            ]);

        $result = $manager->refreshInventory($server);

        $server->refresh();
        $meta = $server->meta;

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame(['8.4', '8.3'], $meta['php_inventory']['installed_versions']);
        $this->assertSame('8.4', $meta['php_inventory']['detected_default_version']);
        $this->assertSame('8.4', $meta['default_php_version']);
        $this->assertSame('8.3', $meta['php_new_site_default_version']);
        $this->assertSame('succeeded', $meta['php_inventory_refresh']['status']);
        $this->assertSame('2026-03-31T12:00:00+00:00', $meta['php_inventory_refresh']['refreshed_at']);
        $this->assertNull($meta['php_inventory_refresh']['error'] ?? null);
    }

    #[Test]
    public function it_persists_refresh_failure_metadata_when_remote_inventory_collection_fails(): void
    {
        CarbonImmutable::setTestNow('2026-03-31 12:00:00');

        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andThrow(new \RuntimeException('apt cache lock timeout'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('apt cache lock timeout');

        try {
            $manager->refreshInventory($server);
        } finally {
            $server->refresh();
            $meta = $server->meta;

            $this->assertSame('failed', $meta['php_inventory_refresh']['status']);
            $this->assertSame('apt cache lock timeout', $meta['php_inventory_refresh']['error']);
            $this->assertSame('2026-03-31T12:00:00+00:00', $meta['php_inventory_refresh']['failed_at']);
        }
    }

    #[Test]
    public function it_marks_the_inventory_as_stale_when_remote_refresh_succeeds_but_full_persistence_fails(): void
    {
        CarbonImmutable::setTestNow('2026-03-31 12:00:00');

        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'installed_versions' => ['8.2'],
                'detected_default_version' => '8.2',
            ],
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.3',
            ]);
        $manager->shouldReceive('persistRefreshedInventoryMeta')
            ->once()
            ->andThrow(new \RuntimeException('metadata write failed'));

        $result = $manager->refreshInventory($server);

        $server->refresh();
        $meta = $server->meta;

        $this->assertSame('stale', $result['status']);
        $this->assertSame(['8.2'], $meta['php_inventory']['installed_versions']);
        $this->assertSame('stale', $meta['php_inventory_refresh']['status']);
        $this->assertSame('metadata write failed', $meta['php_inventory_refresh']['error']);
        $this->assertSame('2026-03-31T12:00:00+00:00', $meta['php_inventory_refresh']['stale_at']);
    }

    #[Test]
    public function it_installs_a_supported_version_after_revalidating_fresh_inventory(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ],
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ]);
        $manager->shouldReceive('executePackageAction')
            ->once()
            ->withArgs(fn (Server $refreshedServer, string $action, string $version) => $refreshedServer->is($server) && $action === 'install' && $version === '8.4');
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.3', '8.4'],
                'detected_default_version' => '8.3',
            ]);

        $result = $manager->applyPackageAction($server, 'install', '8.4');

        $server->refresh();

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('8.3', $server->meta['default_php_version']);
        $this->assertSame(['8.3', '8.4'], $server->meta['php_inventory']['installed_versions']);
    }

    #[Test]
    public function it_sets_the_cli_default_after_revalidating_fresh_inventory(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.3',
            ],
            'default_php_version' => '8.3',
            'php_new_site_default_version' => '8.3',
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ]);
        $manager->shouldReceive('executePackageAction')->once();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ]);

        $result = $manager->applyPackageAction($server, 'set_cli_default', '8.4');

        $server->refresh();

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('8.4', $server->meta['default_php_version']);
        $this->assertSame('8.3', $server->meta['php_new_site_default_version']);
    }

    #[Test]
    public function it_sets_the_new_site_default_without_changing_the_cli_default(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
            'default_php_version' => '8.4',
            'php_new_site_default_version' => '8.4',
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.3',
            ]);
        $manager->shouldReceive('executePackageAction')->once();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ]);

        $result = $manager->applyPackageAction($server, 'set_new_site_default', '8.3');

        $server->refresh();

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('8.4', $server->meta['default_php_version']);
        $this->assertSame('8.3', $server->meta['php_new_site_default_version']);
    }

    #[Test]
    public function it_patches_an_installed_version(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ]);
        $manager->shouldReceive('executePackageAction')
            ->once()
            ->withArgs(fn (Server $refreshedServer, string $action, string $version) => $refreshedServer->is($server) && $action === 'patch' && $version === '8.4');
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ]);

        $result = $manager->applyPackageAction($server, 'patch', '8.4');

        $this->assertSame('succeeded', $result['status']);
    }

    #[Test]
    public function it_allows_patch_when_remote_inventory_reports_the_detected_default_version_only(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'default_php_version' => '8.3',
            'php_version' => '8.3',
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => [],
                'detected_default_version' => '8.3',
            ]);
        $manager->shouldReceive('executePackageAction')
            ->once()
            ->withArgs(fn (Server $refreshedServer, string $action, string $version) => $refreshedServer->is($server) && $action === 'patch' && $version === '8.3');
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => [],
                'detected_default_version' => '8.3',
            ]);

        $result = $manager->applyPackageAction($server, 'patch', '8.3');

        $this->assertSame('succeeded', $result['status']);
    }

    #[Test]
    public function the_remote_inventory_script_counts_fpm_packages_as_installed_versions(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
        ]);

        $manager = new class extends ServerPhpManager
        {
            public function inventoryScript(Server $server, string $quotedVersions): string
            {
                return $this->privilegedShellScript($server, $quotedVersions);
            }
        };

        $script = $manager->inventoryScript($server, "'8.3' '8.4'");

        $this->assertStringContainsString('"php${version}-cli"', $script);
        $this->assertStringContainsString('"php${version}-fpm"', $script);
        $this->assertStringContainsString('command -v "php${version}"', $script);
        $this->assertStringContainsString('command -v "php-fpm${version}"', $script);
        $this->assertStringContainsString('[ -d "/etc/php/${version}" ]', $script);
    }

    #[Test]
    public function it_blocks_uninstall_when_the_version_is_used_by_a_site(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
            'default_php_version' => '8.4',
            'php_new_site_default_version' => '8.4',
        ]);

        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $server->user_id,
            'organization_id' => $server->organization_id,
            'php_version' => '8.3',
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ]);
        $manager->shouldNotReceive('executePackageAction');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHP 8.3 is still used by 1 site.');

        $manager->applyPackageAction($server, 'uninstall', '8.3');
    }

    #[Test]
    public function it_blocks_uninstall_when_the_version_is_the_cli_default(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
            'default_php_version' => '8.3',
            'php_new_site_default_version' => '8.4',
        ]);

        $lock = Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once();

        Cache::shouldReceive('lock')
            ->once()
            ->with('server-php-package-action:'.$server->id, 630)
            ->andReturn($lock);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.3',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHP 8.3 is still the CLI default for this server.');

        $manager->applyPackageAction($server, 'uninstall', '8.3');
    }

    #[Test]
    public function it_blocks_uninstall_when_the_remote_detected_cli_default_differs_from_stale_persisted_metadata(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
            'default_php_version' => '8.4',
            'php_new_site_default_version' => '8.4',
        ]);

        $lock = Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once();

        Cache::shouldReceive('lock')
            ->once()
            ->with('server-php-package-action:'.$server->id, 630)
            ->andReturn($lock);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.3',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHP 8.3 is still the CLI default for this server.');

        $manager->applyPackageAction($server, 'uninstall', '8.3');
    }

    #[Test]
    public function it_blocks_uninstall_when_the_version_is_the_new_site_default(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ],
            'default_php_version' => '8.4',
            'php_new_site_default_version' => '8.3',
        ]);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.4', '8.3'],
                'detected_default_version' => '8.4',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHP 8.3 is still the default for new PHP sites on this server.');

        $manager->applyPackageAction($server, 'uninstall', '8.3');
    }

    #[Test]
    public function it_rejects_package_actions_when_another_mutation_is_already_running_for_the_server(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
        ]);

        $lock = Cache::lock('server-php-package-action:'.$server->id, 30);
        $this->assertTrue($lock->get());

        try {
            $manager = new ServerPhpManager;

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Another PHP package action is already running for this server.');

            $manager->applyPackageAction($server, 'install', '8.4');
        } finally {
            $lock->release();
        }
    }

    #[Test]
    public function it_uses_a_lock_ttl_that_covers_the_full_remote_package_action_timeout(): void
    {
        $server = $this->makeServerWithMeta([
            'server_role' => 'application',
        ]);

        $lock = Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once();

        Cache::shouldReceive('lock')
            ->once()
            ->with('server-php-package-action:'.$server->id, 630)
            ->andReturn($lock);

        $manager = Mockery::mock(ServerPhpManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.3'],
                'detected_default_version' => '8.3',
            ]);
        $manager->shouldReceive('executePackageAction')->once();
        $manager->shouldReceive('fetchRemoteInventory')
            ->once()
            ->andReturn([
                'supported' => true,
                'installed_versions' => ['8.3', '8.4'],
                'detected_default_version' => '8.3',
            ]);

        $manager->applyPackageAction($server, 'install', '8.4');
    }
}
