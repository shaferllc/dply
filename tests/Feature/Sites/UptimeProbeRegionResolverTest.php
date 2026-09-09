<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\UptimeProbeRegionResolverTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\UptimeProbeRegionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it maps digitalocean regions to the nearest probe', function () {
    $resolver = new UptimeProbeRegionResolver;

    expect($resolver->resolve('nyc1'))->toBe('us-east');
    expect($resolver->resolve('nyc3'))->toBe('us-east');
    expect($resolver->resolve('tor1'))->toBe('us-east');
    expect($resolver->resolve('sfo3'))->toBe('us-west');
    expect($resolver->resolve('ams3'))->toBe('eu-amsterdam');
    expect($resolver->resolve('fra1'))->toBe('eu-frankfurt');
    expect($resolver->resolve('syd1'))->toBe('ap-sydney');
});
test('it maps hetzner regions to the nearest probe', function () {
    $resolver = new UptimeProbeRegionResolver;

    expect($resolver->resolve('fsn1'))->toBe('eu-falkenstein');
    expect($resolver->resolve('nbg1'))->toBe('eu-falkenstein');
    expect($resolver->resolve('hel1'))->toBe('eu-falkenstein');
    expect($resolver->resolve('ash'))->toBe('us-east');
    expect($resolver->resolve('hil'))->toBe('us-west');
    expect($resolver->resolve('sin'))->toBe('ap-sydney');
});
test('an unknown or empty region falls back to the first configured', function () {
    $resolver = new UptimeProbeRegionResolver;
    $first = (string) array_key_first(config('site_uptime.probe_regions'));

    expect($resolver->resolve('mars1'))->toBe($first);
    expect($resolver->resolve(''))->toBe($first);
    expect($resolver->resolve(null))->toBe($first);
});
test('a vm site uses the server region', function () {
    $site = makeResolverSite(serverRegion: 'nyc1');

    expect((new UptimeProbeRegionResolver)->forSite($site))->toBe('us-east');
});
test('a function uses the server region even when the worker is elsewhere', function () {
    $site = makeResolverSite(
        serverRegion: 'nyc1',
        functions: true,
    );

    expect((new UptimeProbeRegionResolver)->forSite($site))->toBe('us-east');
});
test('a function without a server region parses faas host then defaults to nyc1', function () {
    $resolver = new UptimeProbeRegionResolver;

    $fromUrl = makeResolverSite(
        serverRegion: null,
        functions: true,
        actionUrl: 'https://faas-sfo3-2ef2e6cc.doserverless.co/api/v1/web/ns/default/fn',
    );
    expect($resolver->forSite($fromUrl))->toBe('us-west');

    $fallback = makeResolverSite(serverRegion: null, functions: true);
    expect($resolver->forSite($fallback))->toBe('us-east');
});

function makeResolverSite(?string $serverRegion, bool $functions = false, ?string $actionUrl = null): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'region' => $serverRegion,
        'meta' => $functions
            ? ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS]
            : [],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => $functions ? Site::STATUS_FUNCTIONS_ACTIVE : Site::STATUS_NGINX_ACTIVE,
        'meta' => $functions
            ? [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => array_filter(['action_url' => $actionUrl]),
            ]
            : [],
    ]);
}
