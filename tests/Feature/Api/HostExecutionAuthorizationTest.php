<?php

namespace Tests\Feature\Api\HostExecutionAuthorizationTest;

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
 * The case org-membership checks let through: a real member of the right
 * organization, holding a token with the right ability, whose workspace role is
 * viewer. Every route below does work on a customer host, so the ability and
 * the org check are necessary and not sufficient (ISS-009).
 *
 * @return array{0: Server, 1: Site, 2: string}
 */
function viewerWithHostToken(): array
{
    $organization = Organization::factory()->create();
    $viewer = User::factory()->create();
    $organization->users()->attach($viewer->id, ['role' => 'member']);

    $workspace = Workspace::factory()->create(['organization_id' => $organization->id]);
    WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $viewer->id, 'role' => 'viewer']);

    $server = Server::factory()->ready()->create([
        'organization_id' => $organization->id,
        'user_id' => $viewer->id,
        'workspace_id' => $workspace->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $viewer->id,
        'workspace_id' => $workspace->id,
    ]);

    ['plaintext' => $token] = ApiToken::createToken(
        $viewer,
        $organization,
        'test',
        null,
        ['servers.read', 'sites.read', 'commands.run', 'notifications.write'],
    );

    return [$server, $site, $token];
}

it('confirms the fixture is a genuine org member who simply cannot change the server', function () {
    [$server, $site] = viewerWithHostToken();
    $viewer = $server->user;

    // If this drifts, every assertion below passes for the wrong reason.
    expect($viewer->can('view', $server))->toBeTrue()
        ->and($viewer->can('update', $server))->toBeFalse()
        ->and($viewer->can('update', $site))->toBeFalse();
});

it('refuses log-shipping changes to a viewer', function () {
    Queue::fake();
    [$server, , $token] = viewerWithHostToken();

    $this->withToken($token)->postJson("/api/v1/servers/{$server->id}/log-shipping/enable")->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/servers/{$server->id}/log-shipping/resync")->assertForbidden();
    $this->withToken($token)->deleteJson("/api/v1/servers/{$server->id}/log-shipping")->assertForbidden();
});

it('refuses metrics agent work to a viewer', function () {
    Queue::fake();
    [$server, , $token] = viewerWithHostToken();

    $this->withToken($token)->postJson("/api/v1/servers/{$server->id}/metrics/probe")->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/servers/{$server->id}/metrics/install")->assertForbidden();
    $this->withToken($token)
        ->patchJson("/api/v1/servers/{$server->id}/metrics/thresholds", ['cpu' => 90])
        ->assertForbidden();
});

it('refuses running an arbitrary command to a viewer', function () {
    Queue::fake();
    [$server, , $token] = viewerWithHostToken();

    $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/run-command", ['command' => 'uptime'])
        ->assertForbidden();
});

it('refuses error retry and remediation to a viewer', function () {
    Queue::fake();
    [, $site, $token] = viewerWithHostToken();

    $this->withToken($token)->postJson("/api/v1/sites/{$site->slug}/errors/nope/retry")->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/sites/{$site->slug}/errors/nope/remediate")->assertForbidden();
});

it('still lets a viewer read, so the gate is authorization and not a blanket block', function () {
    [$server, , $token] = viewerWithHostToken();

    $this->withToken($token)->getJson("/api/v1/servers/{$server->id}/log-shipping")->assertOk();
});
