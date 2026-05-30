<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\TraefikDashboardExposure;

test('traefik dashboard public url is null when exposure disabled', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'meta' => ['edge_proxy' => 'traefik'],
    ]);

    $exposure = Mockery::mock(TraefikDashboardExposure::class)->makePartial();
    $exposure->shouldReceive('read')->once()->andReturn([
        'enabled' => false,
        'path' => '/traefik-dashboard',
        'username' => '',
        'has_password' => false,
        'auth_user_line' => null,
    ]);
    $this->instance(TraefikDashboardExposure::class, $exposure);

    expect($exposure->publicUrl($server))->toBeNull();
});

test('traefik dashboard public url uses server ip and configured path', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'meta' => ['edge_proxy' => 'traefik'],
    ]);

    $exposure = Mockery::mock(TraefikDashboardExposure::class)->makePartial();
    $exposure->shouldReceive('read')->once()->andReturn([
        'enabled' => true,
        'path' => '/traefik-dashboard',
        'username' => 'admin',
        'has_password' => true,
        'auth_user_line' => 'admin:$apr1$abc',
    ]);
    $this->instance(TraefikDashboardExposure::class, $exposure);

    expect($exposure->publicUrl($server))
        ->toBe('http://203.0.113.10/traefik-dashboard/');
});
