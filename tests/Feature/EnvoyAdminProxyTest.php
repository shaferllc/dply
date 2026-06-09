<?php

declare(strict_types=1);

namespace Tests\Feature\EnvoyAdminProxyTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\EnvoyAdminProxy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function envoyProxyUser(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

function envoyProxyServer(User $user, array $meta = []): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => array_merge(['edge_proxy' => 'envoy'], $meta),
    ]);
}

test('guests cannot access envoy admin proxy', function () {
    $server = envoyProxyServer(envoyProxyUser());

    $this->get(route('servers.envoy.admin', ['server' => $server]))
        ->assertRedirect('/login');
});

test('authenticated user receives proxied envoy admin html', function () {
    $user = envoyProxyUser();
    $server = envoyProxyServer($user);

    $this->mock(EnvoyAdminProxy::class, function ($mock) use ($server): void {
        $mock->shouldReceive('fetch')
            ->once()
            ->withArgs(fn ($s, $path, $prefix) => $s->is($server) && $path === '' && str_contains($prefix, '/envoy/admin'))
            ->andReturn([
                'status' => 200,
                'body' => '<html><body>Envoy</body></html>',
                'content_type' => 'text/html; charset=utf-8',
                'target_url' => EnvoyAdminProxy::LOCAL_BASE.'/',
                'admin_url' => EnvoyAdminProxy::LOCAL_BASE,
            ]);
    });

    $this->actingAs($user)
        ->get(route('servers.envoy.admin', ['server' => $server]))
        ->assertOk()
        ->assertSee('Envoy', false);
});

test('servers without envoy edge proxy return 404', function () {
    $user = envoyProxyUser();
    $server = envoyProxyServer($user, ['edge_proxy' => 'haproxy']);

    $this->actingAs($user)
        ->get(route('servers.envoy.admin', ['server' => $server]))
        ->assertNotFound();
});
