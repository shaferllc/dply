<?php


namespace Tests\Unit\Services\ServerPhpManagerTest;
use App\Models\Organization;
use \App\Services\Servers\ServerPhpManager;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeServerWithMeta(array $meta = []): Server
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

it('selects supported versions for the server role', function () {
    $server = makeServerWithMeta([
        'server_role' => 'application',
    ]);

    $manager = new ServerPhpManager;
    $supported = $manager->supportedVersions($server);

    $ids = array_column($supported, 'id');

    expect($ids)->toContain('8.4');
    expect($ids)->toContain('8.3');
    expect($ids)->toContain('7.4');
    expect($ids)->not->toContain('none');
});

it('excludes php versions when the server role does not support php', function () {
    $server = makeServerWithMeta([
        'server_role' => 'plain',
    ]);

    $manager = new ServerPhpManager;

    expect($manager->supportedVersions($server))->toBe([]);
});

it('normalizes cached installed versions and defaults', function () {
    $server = makeServerWithMeta([
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

    expect($inventory['is_supported_environment'])->toBeTrue();
    expect(array_column($inventory['installed_versions'], 'id'))->toBe(['8.4', '8.3', '8.2']);
    expect($defaults['cli_default'])->toBe('8.3');
    expect($defaults['new_site_default'])->toBe('8.2');
    expect($inventory['detected_default_version'])->toBe('8.3');
});

it('falls back when the cached cli default is not installed', function () {
    $server = makeServerWithMeta([
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

    expect($manager->currentDefaults($server))->toBe([
        'cli_default' => '8.4',
        'new_site_default' => '8.3',
    ]);
});

it('reconciles fresh inventory using remote state as source of truth', function () {
    $server = makeServerWithMeta([
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

    expect($reconciled['php_inventory']['installed_versions'])->toBe(['8.4', '8.3']);
    expect($reconciled['php_inventory']['detected_default_version'])->toBe('8.4');
    expect($reconciled['default_php_version'])->toBe('8.4');
    expect($reconciled['php_new_site_default_version'])->toBe('8.4');
});

it('preserves a valid user new site default when inventory disagrees with cached metadata', function () {
    $server = makeServerWithMeta([
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

    expect($reconciled['default_php_version'])->toBe('8.4');
    expect($reconciled['php_new_site_default_version'])->toBe('8.3');
});

it('replaces an invalid user new site default with the remote default', function () {
    $server = makeServerWithMeta([
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

    expect($reconciled['php_new_site_default_version'])->toBe('8.4');
});

it('persists the refreshed php inventory snapshot defaults and timestamp', function () {
    CarbonImmutable::setTestNow('2026-03-31 12:00:00');

    $server = makeServerWithMeta([
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

    expect($result['status'])->toBe('succeeded');
    expect($meta['php_inventory']['installed_versions'])->toBe(['8.4', '8.3']);
    expect($meta['php_inventory']['detected_default_version'])->toBe('8.4');
    expect($meta['default_php_version'])->toBe('8.4');
    expect($meta['php_new_site_default_version'])->toBe('8.3');
    expect($meta['php_inventory_refresh']['status'])->toBe('succeeded');
    expect($meta['php_inventory_refresh']['refreshed_at'])->toBe('2026-03-31T12:00:00+00:00');
    expect($meta['php_inventory_refresh']['error'] ?? null)->toBeNull();
});

it('persists refresh failure metadata when remote inventory collection fails', function () {
    CarbonImmutable::setTestNow('2026-03-31 12:00:00');

    $server = makeServerWithMeta([
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

        expect($meta['php_inventory_refresh']['status'])->toBe('failed');
        expect($meta['php_inventory_refresh']['error'])->toBe('apt cache lock timeout');
        expect($meta['php_inventory_refresh']['failed_at'])->toBe('2026-03-31T12:00:00+00:00');
    }
});

it('marks the inventory as stale when remote refresh succeeds but full persistence fails', function () {
    CarbonImmutable::setTestNow('2026-03-31 12:00:00');

    $server = makeServerWithMeta([
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

    expect($result['status'])->toBe('stale');
    expect($meta['php_inventory']['installed_versions'])->toBe(['8.2']);
    expect($meta['php_inventory_refresh']['status'])->toBe('stale');
    expect($meta['php_inventory_refresh']['error'])->toBe('metadata write failed');
    expect($meta['php_inventory_refresh']['stale_at'])->toBe('2026-03-31T12:00:00+00:00');
});

it('installs a supported version after revalidating fresh inventory', function () {
    $server = makeServerWithMeta([
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

    expect($result['status'])->toBe('succeeded');
    expect($server->meta['default_php_version'])->toBe('8.3');
    expect($server->meta['php_inventory']['installed_versions'])->toBe(['8.3', '8.4']);
});

it('sets the cli default after revalidating fresh inventory', function () {
    $server = makeServerWithMeta([
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

    expect($result['status'])->toBe('succeeded');
    expect($server->meta['default_php_version'])->toBe('8.4');
    expect($server->meta['php_new_site_default_version'])->toBe('8.3');
});

it('sets the new site default without changing the cli default', function () {
    $server = makeServerWithMeta([
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

    expect($result['status'])->toBe('succeeded');
    expect($server->meta['default_php_version'])->toBe('8.4');
    expect($server->meta['php_new_site_default_version'])->toBe('8.3');
});

it('patches an installed version', function () {
    $server = makeServerWithMeta([
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

    expect($result['status'])->toBe('succeeded');
});

it('allows patch when remote inventory reports the detected default version only', function () {
    $server = makeServerWithMeta([
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

    expect($result['status'])->toBe('succeeded');
});

test('the remote inventory script counts fpm packages as installed versions', function () {
    $server = makeServerWithMeta([
        'server_role' => 'application',
    ]);

    $manager = new class extends ServerPhpManager
    {
        function inventoryScript(Server $server, string $quotedVersions): string
        {
            return $this->privilegedShellScript($server, $quotedVersions);
        }
    };

    $script = inventoryScript($server, "'8.3' '8.4'");

    $this->assertStringContainsString('"php${version}-cli"', $script);
    $this->assertStringContainsString('"php${version}-fpm"', $script);
    $this->assertStringContainsString('command -v "php${version}"', $script);
    $this->assertStringContainsString('command -v "php-fpm${version}"', $script);
    $this->assertStringContainsString('[ -d "/etc/php/${version}" ]', $script);
});

it('blocks uninstall when the version is used by a site', function () {
    $server = makeServerWithMeta([
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
});

it('blocks uninstall when the version is the cli default', function () {
    $server = makeServerWithMeta([
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
});

it('blocks uninstall when the remote detected cli default differs from stale persisted metadata', function () {
    $server = makeServerWithMeta([
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
});

it('blocks uninstall when the version is the new site default', function () {
    $server = makeServerWithMeta([
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
});

it('rejects package actions when another mutation is already running for the server', function () {
    $server = makeServerWithMeta([
        'server_role' => 'application',
    ]);

    $lock = Cache::lock('server-php-package-action:'.$server->id, 30);
    expect($lock->get())->toBeTrue();

    try {
        $manager = new ServerPhpManager;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Another PHP package action is already running for this server.');

        $manager->applyPackageAction($server, 'install', '8.4');
    } finally {
        $lock->release();
    }
});

it('uses a lock ttl that covers the full remote package action timeout', function () {
    $server = makeServerWithMeta([
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
});