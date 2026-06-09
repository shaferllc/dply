<?php

declare(strict_types=1);

namespace Tests\Feature\TraefikDashboardProxyTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\TraefikDashboardProxy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function traefikProxyUser(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

function traefikProxyServer(User $user, array $meta = []): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => array_merge(['edge_proxy' => 'traefik'], $meta),
    ]);
}

test('guests cannot access traefik dashboard proxy', function () {
    $server = traefikProxyServer(traefikProxyUser());

    $this->get(route('servers.traefik.dashboard', ['server' => $server]))
        ->assertRedirect('/login');
});

test('authenticated user receives proxied traefik dashboard html', function () {
    $user = traefikProxyUser();
    $server = traefikProxyServer($user);

    $this->mock(TraefikDashboardProxy::class, function ($mock) use ($server): void {
        $mock->shouldReceive('fetch')
            ->once()
            ->withArgs(fn ($s, $path, $prefix) => $s->is($server) && $path === '' && str_contains($prefix, '/traefik/dashboard'))
            ->andReturn([
                'status' => 200,
                'body' => '<html><body>Traefik</body></html>',
                'content_type' => 'text/html; charset=utf-8',
                'target_url' => TraefikDashboardProxy::LOCAL_BASE.'/dashboard/',
                'admin_url' => TraefikDashboardProxy::LOCAL_BASE,
            ]);
    });

    $this->actingAs($user)
        ->get(route('servers.traefik.dashboard', ['server' => $server]))
        ->assertOk()
        ->assertSee('Traefik', false);
});

test('deployers are blocked from traefik dashboard proxy', function () {
    $user = traefikProxyUser('deployer');
    $server = traefikProxyServer($user);

    $this->actingAs($user)
        ->get(route('servers.traefik.dashboard', ['server' => $server]))
        ->assertForbidden();
});

test('servers without traefik edge proxy return 404', function () {
    $user = traefikProxyUser();
    $server = traefikProxyServer($user, ['edge_proxy' => 'haproxy']);

    $this->actingAs($user)
        ->get(route('servers.traefik.dashboard', ['server' => $server]))
        ->assertNotFound();
});

test('traefik dashboard proxy receives full asset path with underscores and dots', function () {
    $user = traefikProxyUser();
    $server = traefikProxyServer($user);

    $this->mock(TraefikDashboardProxy::class, function ($mock) use ($server): void {
        $mock->shouldReceive('fetch')
            ->once()
            ->withArgs(fn ($s, $path) => $s->is($server) && $path === 'assets/_hacks.de068122.js')
            ->andReturn([
                'status' => 200,
                'body' => 'export {};',
                'content_type' => 'application/javascript',
                'target_url' => TraefikDashboardProxy::LOCAL_BASE.'/dashboard/assets/_hacks.de068122.js',
                'admin_url' => TraefikDashboardProxy::LOCAL_BASE,
            ]);
    });

    $this->actingAs($user)
        ->get('/servers/'.$server->id.'/traefik/dashboard/assets/_hacks.de068122.js')
        ->assertOk();
});

test('traefik dashboard proxy maps chunks url to upstream underscore asset', function () {
    $user = traefikProxyUser();
    $server = traefikProxyServer($user);

    $this->mock(TraefikDashboardProxy::class, function ($mock) use ($server): void {
        $mock->shouldReceive('fetch')
            ->once()
            ->withArgs(fn ($s, $path) => $s->is($server) && $path === 'assets/chunks/init.2dcf31c3.js')
            ->andReturn([
                'status' => 200,
                'body' => 'export {};',
                'content_type' => 'application/javascript',
                'target_url' => TraefikDashboardProxy::LOCAL_BASE.'/dashboard/assets/_init.2dcf31c3.js',
                'admin_url' => TraefikDashboardProxy::LOCAL_BASE,
            ]);
    });

    $this->actingAs($user)
        ->get('/servers/'.$server->id.'/traefik/dashboard/assets/chunks/init.2dcf31c3.js')
        ->assertOk();
});

test('traefik dashboard assets route proxies to traefik dashboard assets', function () {
    $user = traefikProxyUser();
    $server = traefikProxyServer($user);

    $this->mock(TraefikDashboardProxy::class, function ($mock) use ($server): void {
        $mock->shouldReceive('fetch')
            ->once()
            ->withArgs(fn ($s, $path, $prefix) => $s->is($server)
                && $path === 'assets/index.eb4a9702.js'
                && str_contains($prefix, '/traefik/dashboard'))
            ->andReturn([
                'status' => 200,
                'body' => 'console.log("ok");',
                'content_type' => 'application/javascript',
                'target_url' => TraefikDashboardProxy::LOCAL_BASE.'/dashboard/assets/index.eb4a9702.js',
                'admin_url' => TraefikDashboardProxy::LOCAL_BASE,
            ]);
    });

    $this->actingAs($user)
        ->get(route('servers.traefik.dashboard.assets', [
            'server' => $server,
            'path' => 'index.eb4a9702.js',
        ]))
        ->assertOk();
});
