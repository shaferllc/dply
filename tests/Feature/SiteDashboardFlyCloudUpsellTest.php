<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDashboardFlyCloudUpsellTest extends TestCase
{
    use RefreshDatabase;

    public function test_node_site_shows_dply_cloud_upsell(): void
    {
        [$user, $server, $site] = $this->makeUserSite('node');

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Cloud-eligible')
            ->assertSee('Deploy to dply cloud');
    }

    public function test_static_site_shows_dply_cloud_upsell(): void
    {
        [$user, $server, $site] = $this->makeUserSite('static');

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Cloud-eligible');
    }

    public function test_php_site_does_not_show_upsell(): void
    {
        [$user, $server, $site] = $this->makeUserSite('php');

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertDontSee('Cloud-eligible');
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeUserSite(string $runtime): array
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
}
