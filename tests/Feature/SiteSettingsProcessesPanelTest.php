<?php

declare(strict_types=1);

namespace Tests\Feature\SiteSettingsProcessesPanelTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('processes panel lists web and worker for node site', function () {
    [$user, $server] = makeUserServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'node',
        'start_command' => 'npm start',
        'internal_port' => 30001,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    // Update auto-created web row's command (Site::created hook
    // creates the row with command=null).
    $site->processes()->where('type', SiteProcess::TYPE_WEB)
        ->update(['command' => 'npm start']);
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'worker',
        'command' => 'npm run worker',
    ]);

    // The processes panel lives on the runtime section now (it's logically a
    // runtime-of-this-site concern, not a general-overview one). Test the section
    // where the panel actually renders.
    $response = $this->actingAs($user)->get(route('sites.show', [
        'server' => $server,
        'site' => $site,
        'section' => 'runtime',
    ]));

    $response->assertOk()
        ->assertSee('Site processes')
        ->assertSee('npm start')
        ->assertSee('npm run worker')
        ->assertSee('worker');
});
test('processes panel omitted for static site', function () {
    [$user, $server] = makeUserServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'static',
        'type' => SiteType::Static,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    // The processes panel lives on the runtime section now (it's logically a
    // runtime-of-this-site concern, not a general-overview one). Test the section
    // where the panel actually renders.
    $response = $this->actingAs($user)->get(route('sites.show', [
        'server' => $server,
        'site' => $site,
        'section' => 'runtime',
    ]));

    // Static sites have no SiteProcess rows (Site::created hook
    // skips static type), so the panel doesn't render.
    $response->assertOk()
        ->assertDontSee('Site processes');
});
test('processes panel marks inactive processes', function () {
    [$user, $server] = makeUserServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'node',
        'start_command' => 'npm start',
        'internal_port' => 30001,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
    $site->processes()->where('type', SiteProcess::TYPE_WEB)
        ->update(['command' => 'npm start']);
    $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'inactive-worker',
        'command' => 'node old-worker.js',
        'is_active' => false,
    ]);

    // The processes panel lives on the runtime section now (it's logically a
    // runtime-of-this-site concern, not a general-overview one). Test the section
    // where the panel actually renders.
    $response = $this->actingAs($user)->get(route('sites.show', [
        'server' => $server,
        'site' => $site,
        'section' => 'runtime',
    ]));

    $response->assertOk()
        ->assertSee('inactive-worker')
        ->assertSee('inactive');
});
/**
 * @return array{0: User, 1: Server}
 */
function makeUserServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'webserver' => 'nginx',
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4'],
            ],
        ],
    ]);

    return [$user, $server];
}
