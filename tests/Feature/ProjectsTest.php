<?php

namespace Tests\Feature;

use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => $role]);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_guest_cannot_view_projects(): void
    {
        $this->get(route('projects.index'))->assertRedirect(route('login', absolute: false));
    }

    public function test_authenticated_user_can_view_projects_index(): void
    {
        $user = $this->userWithOrganization();

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Projects');
    }

    public function test_user_can_create_project_via_livewire(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(ProjectsIndex::class)
            ->set('name', 'Production')
            ->set('description', 'Main stack')
            ->call('createProject');

        $this->assertDatabaseHas('workspaces', [
            'organization_id' => $org->id,
            'name' => 'Production',
        ]);
    }

    public function test_user_can_attach_server_to_project(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();

        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'Group A',
        ]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'workspace_id' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ProjectsShow::class, ['workspace' => $workspace])
            ->set('serverToAttach', $server->id)
            ->call('attachServer');

        $this->assertSame($workspace->id, $server->fresh()->workspace_id);
    }

    public function test_org_admin_can_delete_project(): void
    {
        $user = $this->userWithOrganization('admin');
        $org = $user->currentOrganization();

        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ProjectsShow::class, ['workspace' => $workspace])
            ->call('destroyWorkspace');

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    }

    public function test_org_member_cannot_delete_project(): void
    {
        $user = $this->userWithOrganization('member');
        $org = $user->currentOrganization();

        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ProjectsShow::class, ['workspace' => $workspace])
            ->call('destroyWorkspace');

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    }
}
