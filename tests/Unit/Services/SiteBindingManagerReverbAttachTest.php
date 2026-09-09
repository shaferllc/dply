<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteBindingManagerReverbAttachTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: Server}
 */
function reverbFixture(string $hostname = 'app.example.com'): array
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $server->user_id,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => $hostname,
        'is_primary' => true,
    ]);

    return [$site->fresh(), $server];
}

test('self-hosted reverb generates credentials and points the client at the public vhost', function () {
    [$site] = reverbFixture();

    $binding = app(SiteBindingManager::class)
        ->attachExisting($site, 'broadcasting', ['kind' => 'self_hosted']);

    $env = $binding->injected_env;

    expect($env['BROADCAST_CONNECTION'])->toBe('reverb')
        ->and($env['REVERB_APP_KEY'])->not->toBe('')
        ->and($env['REVERB_APP_SECRET'])->not->toBe('')
        // The app and the browser dial the vhost, never the loopback port.
        ->and($env['REVERB_HOST'])->toBe('app.example.com')
        ->and($env['REVERB_PORT'])->toBe('443')
        ->and($env['REVERB_SERVER_HOST'])->toBe('127.0.0.1');
});

test('the secret and the loopback bind never reach the client mirror', function () {
    [$site] = reverbFixture();

    $env = app(SiteBindingManager::class)
        ->attachExisting($site, 'broadcasting', ['kind' => 'self_hosted'])
        ->injected_env;

    expect($env)->toHaveKey('VITE_REVERB_APP_KEY')
        ->and($env)->not->toHaveKey('VITE_REVERB_APP_SECRET')
        ->and($env)->not->toHaveKey('VITE_REVERB_SERVER_HOST')
        ->and($env)->not->toHaveKey('VITE_REVERB_SERVER_PORT');
});

test('a second site on the same server gets its own port instead of fighting for 8080', function () {
    [$first, $server] = reverbFixture('one.example.com');

    $second = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $first->organization_id,
        'user_id' => $server->user_id,
    ]);
    SiteDomain::query()->create([
        'site_id' => $second->id,
        'hostname' => 'two.example.com',
        'is_primary' => true,
    ]);

    $manager = app(SiteBindingManager::class);
    $portOne = $manager->attachExisting($first, 'broadcasting', ['kind' => 'self_hosted'])
        ->injected_env['REVERB_SERVER_PORT'];
    $portTwo = $manager->attachExisting($second->fresh(), 'broadcasting', ['kind' => 'self_hosted'])
        ->injected_env['REVERB_SERVER_PORT'];

    expect($portOne)->toBe('8080')->and($portTwo)->toBe('8081');
});

test('the site meta the webserver builders read is written, so the proxy appears', function () {
    [$site] = reverbFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'broadcasting', ['kind' => 'self_hosted']);

    $site = $site->fresh();

    expect($site->shouldProxyReverbInWebserver())->toBeTrue()
        ->and($site->reverbLocalPort())->toBe(8080)
        ->and($site->reverbWebSocketPath())->toBe('/app');
});

test('reconnecting keeps the credentials and port so live clients are not cut off', function () {
    [$site] = reverbFixture();

    $manager = app(SiteBindingManager::class);
    $first = $manager->attachExisting($site, 'broadcasting', ['kind' => 'self_hosted'])->injected_env;
    $second = $manager->attachExisting($site->fresh(), 'broadcasting', ['kind' => 'self_hosted'])->injected_env;

    expect($second['REVERB_APP_KEY'])->toBe($first['REVERB_APP_KEY'])
        ->and($second['REVERB_APP_SECRET'])->toBe($first['REVERB_APP_SECRET'])
        ->and($second['REVERB_SERVER_PORT'])->toBe($first['REVERB_SERVER_PORT']);
});

test('a site with no hostname is refused rather than wired to an unreachable vhost', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'ip_address' => '203.0.113.11']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $server->user_id,
        'meta' => [],
    ]);

    expect(fn () => app(SiteBindingManager::class)->attachExisting($site, 'broadcasting', ['kind' => 'self_hosted']))
        ->toThrow(\InvalidArgumentException::class);
});
