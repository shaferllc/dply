<?php

namespace Tests\Feature;

use App\Livewire\Status\PublicPage;
use App\Livewire\StatusPages\Index as StatusPagesIndex;
use App\Models\Organization;
use App\Models\Server;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatusPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrg(string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_guest_can_view_public_status_page_when_public(): void
    {
        $user = $this->userWithOrg();
        $org = $user->currentOrganization();

        $page = StatusPage::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'API',
            'is_public' => true,
        ]);

        $response = $this->get(route('status.public', $page));

        $response->assertOk();
        $response->assertSee('API');
    }

    public function test_guest_cannot_view_private_status_page(): void
    {
        $user = $this->userWithOrg();
        $org = $user->currentOrganization();

        $page = StatusPage::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'is_public' => false,
        ]);

        $this->get(route('status.public', $page))->assertNotFound();
    }

    public function test_user_can_create_status_page(): void
    {
        $user = $this->userWithOrg();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(StatusPagesIndex::class)
            ->set('name', 'Production API')
            ->call('createPage');

        $this->assertDatabaseHas('status_pages', [
            'organization_id' => $org->id,
            'name' => 'Production API',
        ]);
    }

    public function test_public_page_shows_monitor_state(): void
    {
        $user = $this->userWithOrg();
        $org = $user->currentOrganization();

        $page = StatusPage::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'is_public' => true,
        ]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Web 1',
            'health_status' => Server::HEALTH_REACHABLE,
        ]);

        $page->monitors()->create([
            'monitorable_type' => Server::class,
            'monitorable_id' => $server->id,
            'sort_order' => 0,
        ]);

        Livewire::test(PublicPage::class, ['statusPage' => $page->fresh()])
            ->assertSee('Web 1')
            ->assertSee('Operational');
    }
}
