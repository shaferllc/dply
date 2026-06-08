<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\ServerHostingPlatformContextTest;

use App\Enums\ServerProvider;
use App\Services\VultrService;
use App\Support\Servers\ServerHostingPlatformContext;

test('fromConfig defaults to the hetzner backend and its catalog', function () {
    config([
        'managed_servers.provider' => 'hetzner',
        'managed_servers.hetzner.api_token' => 'h-tok',
    ]);

    $ctx = ServerHostingPlatformContext::fromConfig();

    expect($ctx->provider)->toBe(ServerProvider::Hetzner)
        ->and($ctx->configured())->toBeTrue()
        ->and($ctx->regions())->toHaveKey('fsn1')
        ->and(collect($ctx->sizes())->pluck('slug'))->toContain('cx22');
});

test('fromConfig resolves the vultr backend with its catalog, defaults and service', function () {
    config([
        'managed_servers.provider' => 'vultr',
        'managed_servers.vultr.api_token' => 'v-tok',
    ]);

    $ctx = ServerHostingPlatformContext::fromConfig();

    expect($ctx->provider)->toBe(ServerProvider::Vultr)
        ->and($ctx->configured())->toBeTrue()
        ->and($ctx->defaultRegion)->toBe('ewr')
        ->and($ctx->defaultImage)->toBe('2152')
        ->and($ctx->regions())->toHaveKey('ewr')
        ->and(collect($ctx->sizes())->pluck('slug'))->toContain('vc2-2c-4gb')
        ->and($ctx->vultr())->toBeInstanceOf(VultrService::class);
});

test('configured is false when the active backend token is missing', function () {
    config([
        'managed_servers.provider' => 'vultr',
        'managed_servers.vultr.api_token' => '',
    ]);

    expect(ServerHostingPlatformContext::fromConfig()->configured())->toBeFalse();
});

test('an unknown provider value falls back to hetzner', function () {
    config([
        'managed_servers.provider' => 'nonsense',
        'managed_servers.hetzner.api_token' => 'h-tok',
    ]);

    expect(ServerHostingPlatformContext::fromConfig()->provider)->toBe(ServerProvider::Hetzner);
});
