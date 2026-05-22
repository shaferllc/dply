<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDashboardFlyCloudUpsellTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('node site shows dply cloud upsell', function () {
    [$user, $server, $site] = makeUserSite('node');

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Cloud-eligible')
        ->assertSee('Deploy to dply cloud');
});
test('static site shows dply cloud upsell', function () {
    [$user, $server, $site] = makeUserSite('static');

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Cloud-eligible');
});
test('php site does not show upsell', function () {
    [$user, $server, $site] = makeUserSite('php');

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertDontSee('Cloud-eligible');
});
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeUserSite(string $runtime): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => $runtime,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}
