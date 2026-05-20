<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class EdgeNavLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_dashboard_includes_edge_link_when_surface_edge_active(): void
    {
        Feature::define('surface.edge', fn () => true);
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Edge sites')
            ->assertSee(route('edge.index'), escape: false);
    }

    public function test_edge_link_hidden_when_surface_edge_inactive(): void
    {
        // Default production state: surface.edge is OFF.
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertDontSee('Edge sites');
    }

    public function test_unauthenticated_root_does_not_show_edge_link(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('Edge sites');
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
