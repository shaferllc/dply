<?php

declare(strict_types=1);

namespace Tests\Feature\Api\ProjectApiTest;

use App\Jobs\RunWorkspaceDeployJob;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: User, 2: string, 3: ApiToken}
 */
function projectApiContext(array $abilities): array
{
    $org = Organization::factory()->create(['name' => 'Acme Ops']);
    $user = User::factory()->create(['name' => 'Taylor', 'email' => 'taylor@example.com']);
    $org->users()->attach($user->id, ['role' => 'owner']);

    ['token' => $token, 'plaintext' => $plain] = ApiToken::createToken(
        $user,
        $org,
        'dply CLI',
        null,
        $abilities,
    );

    return [$org, $user, $plain, $token];
}

test('projects index lists visible workspaces', function (): void {
    [$org, $user, $plain] = projectApiContext(['projects.read']);

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Production',
    ]);

    Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    $this->getJson('/api/v1/projects', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Production')
        ->assertJsonPath('data.0.servers_count', 1);
});

test('projects store creates a workspace', function (): void {
    [$org, , $plain] = projectApiContext(['projects.write']);

    $this->postJson('/api/v1/projects', [
        'name' => 'New stack',
        'description' => 'Grouped resources',
    ], [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'New stack');

    $this->assertDatabaseHas('workspaces', [
        'organization_id' => $org->id,
        'name' => 'New stack',
    ]);
});

test('projects deploy queues a workspace deploy run', function (): void {
    Queue::fake();

    [$org, $user, $plain] = projectApiContext(['projects.read', 'projects.deploy']);

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Delivery',
    ]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
    ]);

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'server_id' => $server->id,
        'workspace_id' => $workspace->id,
        'git_repository_url' => 'https://github.com/acme/app.git',
    ]);

    $this->postJson('/api/v1/projects/'.$workspace->id.'/deploy', [
        'site_ids' => [$site->id],
    ], [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(RunWorkspaceDeployJob::class);
});

test('projects attach server adds resource to workspace', function (): void {
    [$org, $user, $plain] = projectApiContext(['projects.write', 'servers.read']);

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'workspace_id' => null,
    ]);

    $this->postJson('/api/v1/projects/'.$workspace->id.'/servers/'.$server->id.'/attach', [], [
        'Authorization' => 'Bearer '.$plain,
    ])->assertOk();

    expect($server->fresh()->workspace_id)->toBe($workspace->id);
});

test('account projects returns project summaries', function (): void {
    [$org, $user, $plain] = projectApiContext(['projects.read', 'account.read']);

    Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Grouped',
    ]);

    $this->getJson('/api/v1/account/projects', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Grouped');
});

test('account show includes projects count', function (): void {
    [$org, $user, $plain] = projectApiContext(['account.read', 'projects.read']);

    Workspace::factory()->count(2)->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $this->getJson('/api/v1/account', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonPath('data.organization.projects_count', 2);
});

test('non member cannot view restricted project', function (): void {
    $org = Organization::factory()->create(['name' => 'Acme Ops']);
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach($member->id, ['role' => 'member']);

    ['plaintext' => $plain] = ApiToken::createToken(
        $member,
        $org,
        'dply CLI',
        null,
        ['projects.read'],
    );

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'name' => 'Private',
    ]);

    WorkspaceMember::query()->where('workspace_id', $workspace->id)->delete();

    $this->getJson('/api/v1/projects/'.$workspace->id, [
        'Authorization' => 'Bearer '.$plain,
    ])->assertForbidden();
});
