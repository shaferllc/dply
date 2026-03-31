<?php

namespace Tests\Feature;

use App\Jobs\RunWorkspaceDeployJob;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        $workspace = Workspace::query()->where('organization_id', $org->id)->where('name', 'Production')->firstOrFail();

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMember::ROLE_OWNER,
        ]);
        $this->assertDatabaseHas('workspace_environments', [
            'workspace_id' => $workspace->id,
            'slug' => 'production',
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

    public function test_org_member_without_project_membership_cannot_view_project(): void
    {
        $owner = $this->userWithOrganization();
        $org = $owner->currentOrganization();
        $member = User::factory()->create();
        $org->users()->attach($member->id, ['role' => 'member']);

        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
        ]);

        session(['current_organization_id' => $org->id]);

        $this->actingAs($member)
            ->get(route('projects.show', $workspace))
            ->assertForbidden();
    }

    public function test_project_owner_can_add_member_and_variable(): void
    {
        $owner = $this->userWithOrganization();
        $org = $owner->currentOrganization();
        $member = User::factory()->create();
        $org->users()->attach($member->id, ['role' => 'member']);

        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectsShow::class, ['workspace' => $workspace])
            ->set('memberUserId', $member->id)
            ->set('memberRole', WorkspaceMember::ROLE_VIEWER)
            ->call('addMember')
            ->set('variableKey', 'SHARED_TOKEN')
            ->set('variableValue', 'abc123')
            ->set('variableIsSecret', true)
            ->call('saveVariable');

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => WorkspaceMember::ROLE_VIEWER,
        ]);
        $this->assertDatabaseHas('workspace_variables', [
            'workspace_id' => $workspace->id,
            'env_key' => 'SHARED_TOKEN',
            'is_secret' => true,
        ]);
    }

    public function test_project_owner_can_add_environment_and_runbook(): void
    {
        $owner = $this->userWithOrganization();
        $org = $owner->currentOrganization();

        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectsShow::class, ['workspace' => $workspace])
            ->set('environmentName', 'QA')
            ->set('environmentDescription', 'Customer acceptance')
            ->call('addEnvironment')
            ->set('runbookTitle', 'Rollback')
            ->set('runbookUrl', 'https://example.com/rollback')
            ->set('runbookBody', 'Rollback checklist')
            ->call('addRunbook');

        $this->assertDatabaseHas('workspace_environments', [
            'workspace_id' => $workspace->id,
            'slug' => 'qa',
        ]);
        $this->assertDatabaseHas('workspace_runbooks', [
            'workspace_id' => $workspace->id,
            'title' => 'Rollback',
        ]);
    }

    public function test_project_owner_can_save_notification_routing(): void
    {
        $owner = $this->userWithOrganization();
        $org = $owner->currentOrganization();
        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
        ]);
        $channel = NotificationChannel::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(ProjectsShow::class, ['workspace' => $workspace])
            ->set('selectedProjectChannelIds', [$channel->id])
            ->set('selectedProjectEventKeys', ['project.deployments', 'project.health'])
            ->call('saveNotifications');

        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Workspace::class,
            'subscribable_id' => $workspace->id,
            'event_key' => 'project.deployments',
        ]);
        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Workspace::class,
            'subscribable_id' => $workspace->id,
            'event_key' => 'project.health',
        ]);
    }

    public function test_project_deployer_can_queue_workspace_deploy(): void
    {
        Queue::fake();

        $owner = $this->userWithOrganization();
        $org = $owner->currentOrganization();
        $deployer = User::factory()->create();
        $org->users()->attach($deployer->id, ['role' => 'member']);

        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
        ]);
        $workspace->members()->create([
            'user_id' => $deployer->id,
            'role' => WorkspaceMember::ROLE_DEPLOYER,
        ]);
        $server = Server::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'workspace_id' => $workspace->id,
        ]);
        $site = Site::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'server_id' => $server->id,
            'workspace_id' => $workspace->id,
        ]);

        Livewire::actingAs($deployer)
            ->test(ProjectsShow::class, ['workspace' => $workspace])
            ->set('selectedDeploySiteIds', [$site->id])
            ->call('queueWorkspaceDeploy');

        $this->assertDatabaseHas('workspace_deploy_runs', [
            'workspace_id' => $workspace->id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(RunWorkspaceDeployJob::class);
    }

    public function test_project_operations_page_shows_humanized_activity_entries_with_links(): void
    {
        $owner = $this->userWithOrganization();
        $org = $owner->currentOrganization();
        $workspace = Workspace::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'name' => 'Customer Stack',
        ]);
        $server = Server::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'workspace_id' => $workspace->id,
            'name' => 'App Server',
        ]);
        $site = Site::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'server_id' => $server->id,
            'workspace_id' => $workspace->id,
            'name' => 'Marketing Site',
        ]);

        audit_log($org, $owner, 'project.server_attached', $workspace, null, [
            'server_id' => $server->id,
            'server_name' => $server->name,
        ]);
        audit_log($org, $owner, 'project.site_attached', $workspace, null, [
            'site_id' => $site->id,
            'site_name' => $site->name,
        ]);
        audit_log($org, $owner, 'project.member_updated', $workspace, null, [
            'member_id' => $owner->id,
            'member_name' => $owner->name,
            'role' => WorkspaceMember::ROLE_OWNER,
        ]);

        $response = $this->actingAs($owner)->get(route('projects.operations', $workspace));

        $response->assertOk()
            ->assertSee('Server added to project')
            ->assertSee('Site added to project')
            ->assertSee('Project member role updated')
            ->assertSee('By '.$owner->name)
            ->assertSee(route('servers.show', $server), escape: false)
            ->assertSee(route('sites.show', [$site->server, $site]), escape: false)
            ->assertSee(route('projects.access', $workspace), escape: false);
    }

    public function test_audit_log_action_summary_humanizes_project_events(): void
    {
        $log = new AuditLog([
            'action' => 'project.deploy.failed',
        ]);

        $this->assertSame('Project deploy failed', $log->action_summary);
    }
}
