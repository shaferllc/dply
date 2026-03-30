<?php

namespace Tests\Feature;

use App\Livewire\Servers\Create as ServersCreate;
use App\Livewire\Servers\Index as ServersIndex;
use App\Livewire\Servers\Show;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServerTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_servers_index_redirects_guest(): void
    {
        $response = $this->get(route('servers.index'));

        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_servers_index_is_displayed_for_authenticated_user(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertSee('Servers');
    }

    public function test_servers_index_lists_servers_in_current_organization(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'My Server',
        ]);

        $response = $this->actingAs($user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertSee('My Server');
    }

    public function test_servers_index_search_filters_by_name(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'demo-alpha-unique-xyz',
        ]);
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'demo-beta-unique-xyz',
        ]);

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('search', 'alpha-unique')
            ->assertSee('demo-alpha-unique-xyz')
            ->assertDontSee('demo-beta-unique-xyz');
    }

    public function test_servers_index_status_filter_limits_rows(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'srv-ready-filter-xyz',
            'status' => Server::STATUS_READY,
        ]);
        Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'srv-error-filter-xyz',
            'status' => Server::STATUS_ERROR,
        ]);

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('statusFilter', Server::STATUS_ERROR)
            ->assertSee('srv-error-filter-xyz')
            ->assertDontSee('srv-ready-filter-xyz');
    }

    public function test_servers_index_reset_filters_clears_state(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->set('search', 'anything')
            ->set('statusFilter', Server::STATUS_READY)
            ->set('sort', 'name')
            ->set('viewMode', 'grid')
            ->call('resetFilters')
            ->assertSet('search', '')
            ->assertSet('statusFilter', '')
            ->assertSet('sort', 'created_at')
            ->assertSet('viewMode', 'list');
    }

    public function test_servers_index_destroy_accepts_string_ulid_and_deletes(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $id = (string) $server->getKey();

        Livewire::actingAs($user)
            ->test(ServersIndex::class)
            ->call('destroy', $id);

        $this->assertModelMissing($server);
    }

    public function test_servers_create_requires_organization(): void
    {
        $user = User::factory()->create();
        // No organization, no session

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertForbidden();
    }

    public function test_servers_create_is_displayed_with_organization(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('servers.create'));

        $response->assertOk();
        $response->assertSee('Create server');
    }

    public function test_servers_can_be_stored_as_custom(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.type', 'custom')
            ->set('form.name', 'Custom Box')
            ->set('form.ip_address', '192.168.1.1')
            ->set('form.ssh_port', '22')
            ->set('form.ssh_user', 'root')
            ->set('form.ssh_private_key', "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNza2FzZWFlndm\n-----END OPENSSH PRIVATE KEY-----")
            ->call('store')
            ->assertRedirect();

        $this->assertDatabaseHas('servers', [
            'name' => 'Custom Box',
            'organization_id' => $org->id,
            'provider' => 'custom',
            'status' => 'ready',
        ]);
    }

    public function test_servers_show_is_displayed_for_owner(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Test Server',
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee('Test Server');
    }

    public function test_servers_show_returns_403_for_non_member(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($otherUser->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $otherUser->id,
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($user)->get(route('servers.show', $server));

        $response->assertForbidden();
    }

    public function test_servers_can_be_destroyed_by_owner(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['server' => $server])
            ->call('destroy')
            ->assertRedirect(route('servers.index'));

        $this->assertModelMissing($server);
    }
}
