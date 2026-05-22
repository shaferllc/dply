<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class CloudNavLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dashboard_includes_cloud_link_when_surface_cloud_active(): void
    {
        Feature::define('surface.cloud', fn () => true);
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Cloud sites')
            ->assertSee(route('cloud.index'), escape: false);
    }

    public function test_cloud_link_hidden_when_surface_cloud_inactive(): void
    {
        // Default production state: surface.cloud is OFF.
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertDontSee('Cloud sites');
    }

    public function test_unauthenticated_root_does_not_show_cloud_link(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('Cloud sites');
    }

    private function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }
}
