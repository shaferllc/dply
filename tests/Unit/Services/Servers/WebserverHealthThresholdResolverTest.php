<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\WebserverHealthThresholdResolverTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Models\WebserverHealthThreshold;
use App\Services\Servers\WebserverHealthThresholdResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
}
test('falls back to config when no overrides', function () {
    $server = makeServer();

    $threshold = app(WebserverHealthThresholdResolver::class)
        ->resolve($server, 'nginx', 'errors_5xx_per_min');

    expect($threshold)->not->toBeNull();
    expect($threshold['comparator'])->toBe('gt');
    expect($threshold['value'])->toBe(10.0);
    // From config/server_metrics.php
    expect($threshold['severity'])->toBe('warning');
});
test('org default wins over config fallback', function () {
    $server = makeServer();
    WebserverHealthThreshold::query()->create([
        'organization_id' => $server->organization_id,
        'server_id' => null,
        'engine' => null,
        'metric' => 'errors_5xx_per_min',
        'comparator' => 'gt',
        'value' => 99,
        'severity' => 'critical',
    ]);

    $threshold = app(WebserverHealthThresholdResolver::class)
        ->resolve($server, 'nginx', 'errors_5xx_per_min');

    expect($threshold['value'])->toBe(99.0);
    expect($threshold['severity'])->toBe('critical');
});
test('org engine specific wins over org default', function () {
    $server = makeServer();
    WebserverHealthThreshold::query()->create([
        'organization_id' => $server->organization_id,
        'server_id' => null,
        'engine' => null,
        'metric' => 'errors_5xx_per_min',
        'comparator' => 'gt',
        'value' => 99,
        'severity' => 'warning',
    ]);
    WebserverHealthThreshold::query()->create([
        'organization_id' => $server->organization_id,
        'server_id' => null,
        'engine' => 'nginx',
        'metric' => 'errors_5xx_per_min',
        'comparator' => 'gt',
        'value' => 5,
        'severity' => 'critical',
    ]);

    $threshold = app(WebserverHealthThresholdResolver::class)
        ->resolve($server, 'nginx', 'errors_5xx_per_min');

    expect($threshold['value'])->toBe(5.0);
    expect($threshold['severity'])->toBe('critical');

    // Other engines still use org default.
    $caddyThreshold = app(WebserverHealthThresholdResolver::class)
        ->resolve($server, 'caddy', 'errors_5xx_per_min');
    expect($caddyThreshold['value'])->toBe(99.0);
});
test('server override wins over org default', function () {
    $server = makeServer();
    WebserverHealthThreshold::query()->create([
        'organization_id' => $server->organization_id,
        'server_id' => null,
        'engine' => null,
        'metric' => 'errors_5xx_per_min',
        'comparator' => 'gt',
        'value' => 50,
        'severity' => 'warning',
    ]);
    WebserverHealthThreshold::query()->create([
        'organization_id' => null,
        'server_id' => $server->id,
        'engine' => null,
        'metric' => 'errors_5xx_per_min',
        'comparator' => 'gt',
        'value' => 1,
        'severity' => 'critical',
    ]);

    $threshold = app(WebserverHealthThresholdResolver::class)
        ->resolve($server, 'nginx', 'errors_5xx_per_min');

    expect($threshold['value'])->toBe(1.0);
    expect($threshold['severity'])->toBe('critical');
});
test('server engine specific wins over server default', function () {
    $server = makeServer();
    WebserverHealthThreshold::query()->create([
        'server_id' => $server->id,
        'engine' => null,
        'metric' => 'errors_5xx_per_min',
        'comparator' => 'gt',
        'value' => 50,
        'severity' => 'warning',
    ]);
    WebserverHealthThreshold::query()->create([
        'server_id' => $server->id,
        'engine' => 'caddy',
        'metric' => 'errors_5xx_per_min',
        'comparator' => 'gt',
        'value' => 3,
        'severity' => 'critical',
    ]);

    $caddy = app(WebserverHealthThresholdResolver::class)
        ->resolve($server, 'caddy', 'errors_5xx_per_min');
    expect($caddy['value'])->toBe(3.0);

    $nginx = app(WebserverHealthThresholdResolver::class)
        ->resolve($server, 'nginx', 'errors_5xx_per_min');
    expect($nginx['value'])->toBe(50.0);
});
test('trips compares correctly', function () {
    $resolver = app(WebserverHealthThresholdResolver::class);

    expect($resolver->trips(['comparator' => 'gt', 'value' => 10.0, 'severity' => 'warning'], 11.0))->toBeTrue();
    expect($resolver->trips(['comparator' => 'gt', 'value' => 10.0, 'severity' => 'warning'], 10.0))->toBeFalse();
    expect($resolver->trips(['comparator' => 'gte', 'value' => 10.0, 'severity' => 'warning'], 10.0))->toBeTrue();
    expect($resolver->trips(['comparator' => 'lt', 'value' => 5.0, 'severity' => 'warning'], 4.0))->toBeTrue();
    expect($resolver->trips(null, 9999))->toBeFalse();
});
