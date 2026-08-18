<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteBindingManagerDriverDependencyTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site}
 */
function driverSite(): array
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $server->user_id,
    ]);

    return [$site];
}

function attachStore(Site $site, string $type): SiteBinding
{
    return SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => $type,
        'mode' => 'attach_existing',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => $type,
        'injected_env' => [],
    ]);
}

test('cache can use file without redis or a database', function () {
    [$site] = driverSite();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'cache', [
        'driver' => 'file',
    ]);

    expect($binding->injected_env['CACHE_STORE'])->toBe('file');
});

test('cache redis driver is refused without a redis binding', function () {
    [$site] = driverSite();

    expect(fn () => app(SiteBindingManager::class)->attachExisting($site, 'cache', [
        'driver' => 'redis',
    ]))->toThrow(InvalidArgumentException::class, 'Redis');
});

test('cache database driver is refused without a database binding', function () {
    [$site] = driverSite();

    expect(fn () => app(SiteBindingManager::class)->attachExisting($site, 'cache', [
        'driver' => 'database',
    ]))->toThrow(InvalidArgumentException::class, 'database');
});

test('cache redis driver is allowed once redis is attached', function () {
    [$site] = driverSite();
    attachStore($site, 'redis');

    $binding = app(SiteBindingManager::class)->attachExisting($site->fresh(), 'cache', [
        'driver' => 'redis',
    ]);

    expect($binding->injected_env['CACHE_STORE'])->toBe('redis');
});

test('queue database driver is refused without a database binding', function () {
    [$site] = driverSite();

    expect(fn () => app(SiteBindingManager::class)->attachExisting($site, 'queue', [
        'driver' => 'database',
    ]))->toThrow(InvalidArgumentException::class, 'database');
});

test('session blank driver falls back to file when no database is attached', function () {
    [$site] = driverSite();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'session', [
        'driver' => '',
    ]);

    expect($binding->injected_env['SESSION_DRIVER'])->toBe('file');
});

test('session database driver is refused without a database binding', function () {
    [$site] = driverSite();

    expect(fn () => app(SiteBindingManager::class)->attachExisting($site, 'session', [
        'driver' => 'database',
    ]))->toThrow(InvalidArgumentException::class, 'database');
});
