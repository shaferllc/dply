<?php

namespace Tests\Feature\ProjectsTest;

use App\Jobs\RunWorkspaceDeployJob;
use App\Livewire\Projects\Index as ProjectsIndex;
use App\Livewire\Projects\Show as ProjectsShow;
use App\Models\AuditLog;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

function userWithOrganization(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('guest cannot view projects', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login', absolute: false));
});

test('authenticated user can view projects index', function () {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Projects');
});

test('user can create project via livewire', function () {
    $user = userWithOrganization();
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
});

test('user can attach server to project', function () {
    $user = userWithOrganization();
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

    expect($server->fresh()->workspace_id)->toBe($workspace->id);
});

test('org admin can delete project', function () {
    $user = userWithOrganization('admin');
    $org = $user->currentOrganization();

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(ProjectsShow::class, ['workspace' => $workspace])
        ->call('destroyWorkspace');

    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
});

test('org member cannot delete project', function () {
    $user = userWithOrganization('member');
    $org = $user->currentOrganization();

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(ProjectsShow::class, ['workspace' => $workspace])
        ->call('destroyWorkspace');

    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
});

test('org member without project membership cannot view project', function () {
    $owner = userWithOrganization();
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
});

test('project owner can add member and variable', function () {
    $owner = userWithOrganization();
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
});

test('project owner can add environment and runbook', function () {
    $owner = userWithOrganization();
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
});

test('project owner can save notification routing', function () {
    $owner = userWithOrganization();
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
});

test('project deployer can queue workspace deploy', function () {
    Queue::fake();

    $owner = userWithOrganization();
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
});

test('project operations page shows humanized activity entries with links', function () {
    $owner = userWithOrganization();
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
});

test('project operations and delivery pages show readiness guidance', function () {
    $owner = userWithOrganization();
    $org = $owner->currentOrganization();
    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'name' => 'Operations Stack',
    ]);

    $operations = $this->actingAs($owner)->get(route('projects.operations', $workspace));
    $operations->assertOk()
        ->assertSee('Operational readiness')
        ->assertSee('Notification routes');

    $delivery = $this->actingAs($owner)->get(route('projects.delivery', $workspace));
    $delivery->assertOk()
        ->assertSee('Recovery and migration checklist')
        ->assertSee('Shared config is ready');
});

test('audit log action summary humanizes project events', function () {
    $log = new AuditLog([
        'action' => 'project.deploy.failed',
    ]);

    expect($log->action_summary)->toBe('Project deploy failed');
});

test('project resources page shows server and site navigation links', function () {
    $owner = userWithOrganization();
    $org = $owner->currentOrganization();
    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
    ]);
    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'name' => 'API Server',
    ]);
    $site = Site::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'workspace_id' => $workspace->id,
        'name' => 'Customer App',
    ]);

    $response = $this->actingAs($owner)->get(route('projects.resources', $workspace));

    $response->assertOk()
        ->assertSee('API Server')
        ->assertSee('Customer App')
        ->assertSee(route('servers.logs', $server), escape: false)
        ->assertSee(route('servers.monitor', $server), escape: false)
        ->assertSee(route('servers.services', $server), escape: false)
        ->assertSee(route('servers.manage', $server), escape: false)
        ->assertSee(route('sites.show', [$site->server, $site]), escape: false)
        ->assertSee(route('sites.insights', [$site->server, $site]), escape: false);
});

test('project delivery page shows site navigation links', function () {
    $owner = userWithOrganization();
    $org = $owner->currentOrganization();
    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
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
        'name' => 'Docs Site',
    ]);

    $response = $this->actingAs($owner)->get(route('projects.delivery', $workspace));

    $response->assertOk()
        ->assertSee('Docs Site')
        ->assertSee(route('sites.show', [$site->server, $site]), escape: false)
        ->assertSee(route('sites.insights', [$site->server, $site]), escape: false);
});

test('project operations page shows routing and monitoring rollups', function () {
    $owner = userWithOrganization();
    $org = $owner->currentOrganization();
    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
    ]);
    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
        'status' => Server::STATUS_READY,
        'meta' => [
            'monitoring_python_installed' => true,
        ],
    ]);
    Site::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'workspace_id' => $workspace->id,
    ]);
    $channel = NotificationChannel::factory()->create([
        'owner_type' => User::class,
        'owner_id' => $owner->id,
    ]);
    NotificationSubscription::query()->create([
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Workspace::class,
        'subscribable_id' => $workspace->id,
        'event_key' => 'project.health',
    ]);
    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now()->subMinute(),
        'payload' => ['cpu_pct' => 10],
    ]);

    $response = $this->actingAs($owner)->get(route('projects.operations', $workspace));

    $response->assertOk()
        ->assertSee('Notification routes')
        ->assertSee('1 saved')
        ->assertSee('1 event covered')
        ->assertSee('Monitored servers: 1')
        ->assertSee('Servers with samples')
        ->assertSee('1 / 1')
        ->assertSee('Escalation ready');
});
