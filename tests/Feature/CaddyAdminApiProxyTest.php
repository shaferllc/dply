<?php

declare(strict_types=1);

namespace Tests\Feature\CaddyAdminApiProxyTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\CaddyAdminApiProxy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function caddyProxyUser(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

function caddyProxyServer(User $user, array $meta = []): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => array_merge(['webserver' => 'caddy'], $meta),
    ]);
}

test('guests cannot access caddy admin proxy', function () {
    $server = caddyProxyServer(caddyProxyUser());

    $this->get(route('servers.webserver.caddy.admin-api', ['server' => $server, 'path' => 'config']))
        ->assertRedirect('/login');
});

test('authenticated user receives proxied admin json', function () {
    $user = caddyProxyUser();
    $server = caddyProxyServer($user);

    $this->mock(CaddyAdminApiProxy::class, function ($mock): void {
        $mock->shouldReceive('fetch')
            ->once()
            ->withArgs(fn ($server, $path) => $path === 'config')
            ->andReturn([
                'status' => 200,
                'body' => '{"admin":{"listen":"localhost:2019"}}',
                'content_type' => 'application/json; charset=utf-8',
                'admin_url' => 'http://127.0.0.1:2019/config/',
            ]);
    });

    $this->actingAs($user)
        ->get(route('servers.webserver.caddy.admin-api', ['server' => $server, 'path' => 'config']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=utf-8')
        ->assertSee('localhost:2019', false);
});

test('deployers are blocked from caddy admin proxy', function () {
    $user = caddyProxyUser('deployer');
    $server = caddyProxyServer($user);

    $this->actingAs($user)
        ->get(route('servers.webserver.caddy.admin-api', ['server' => $server, 'path' => 'config']))
        ->assertForbidden();
});

test('non-caddy servers return 404', function () {
    $user = caddyProxyUser();
    $server = caddyProxyServer($user, ['webserver' => 'nginx']);

    $this->actingAs($user)
        ->get(route('servers.webserver.caddy.admin-api', ['server' => $server, 'path' => 'config']))
        ->assertNotFound();
});
