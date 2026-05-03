<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDashboardFlyEdgeUpsellTest extends TestCase
{
    use RefreshDatabase;

    public function test_node_site_without_fly_credential_shows_upsell(): void
    {
        [$user, $server, $site] = $this->makeUserSite('node');

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Edge-eligible')
            ->assertSee('Connect Fly.io');
    }

    public function test_static_site_without_fly_credential_shows_upsell(): void
    {
        [$user, $server, $site] = $this->makeUserSite('static');

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Edge-eligible');
    }

    public function test_php_site_does_not_show_upsell(): void
    {
        [$user, $server, $site] = $this->makeUserSite('php');

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertDontSee('Edge-eligible');
    }

    public function test_node_site_with_existing_fly_credential_does_not_show_upsell(): void
    {
        [$user, $server, $site] = $this->makeUserSite('node');
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $site->organization_id,
            'provider' => 'fly_io',
            'name' => 'Already connected',
            'credentials' => ['api_token' => 't'],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertDontSee('Edge-eligible');
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
