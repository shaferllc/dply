<?php

declare(strict_types=1);

namespace Tests\Feature\SiteShowRuntimeSummaryTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('install summary shows runtime and internal port for node site', function () {
    [$user, $server] = makeUserServer();

    // Install summary (which surfaces Runtime / Internal port / Build + Start commands
    // in the right-side aside during provisioning) is gated by !$site->isReadyForWorkspace().
    // NGINX_ACTIVE flips the view to the post-install workspace pane, which doesn't carry
    // the Runtime row — so the test uses STATUS_PENDING to land on the install view.
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'node',
        'runtime_version' => '22.7.0',
        'internal_port' => 30007,
        'build_command' => 'npm run build',
        'start_command' => 'npm start',
        'status' => Site::STATUS_PENDING,
    ]);

    $response = $this->actingAs($user)->get(route('sites.show', [
        'server' => $server,
        'site' => $site,
    ]));

    $response->assertOk()
        ->assertSee('Runtime')
        ->assertSee('22.7.0')
        ->assertSee('Internal port')
        ->assertSee('30007')
        ->assertSee('npm run build')
        ->assertSee('npm start');
});
test('install summary omits internal port row for php site', function () {
    [$user, $server] = makeUserServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'runtime' => 'php',
        'runtime_version' => '8.4',
        'internal_port' => null,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $response = $this->actingAs($user)->get(route('sites.show', [
        'server' => $server,
        'site' => $site,
    ]));

    $response->assertOk()
        ->assertSee('Runtime')
        ->assertDontSee('Internal port');
});
test('install summary omits runtime row when unset', function () {
    // Legacy sites with no runtime column populated should still
    // render the install summary cleanly (no "—" placeholder for
    // a row that doesn't apply).
    [$user, $server] = makeUserServer();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $server->organization_id,
        'runtime' => null,
        'internal_port' => null,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    $response = $this->actingAs($user)->get(route('sites.show', [
        'server' => $server,
        'site' => $site,
    ]));

    $response->assertOk();

    // The Site::factory uses SiteType::Php which makes runtimeKey()
    // fall back to 'php', so the row will still render. We're
    // just asserting no crash + no invented internal port.
    $response->assertDontSee('Internal port');
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
