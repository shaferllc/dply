<?php

declare(strict_types=1);

namespace Tests\Feature\Services\RemoteCli\RemoteCliPermissionsTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Modules\RemoteCli\Services\RemoteCliPermissionDeniedException;
use App\Modules\RemoteCli\Services\RemoteCliPermissions;
use App\Modules\RemoteCli\Services\RiskLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeUserWithRole(?string $role): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    if ($role !== null) {
        $org->users()->attach($user->id, ['role' => $role]);
    }
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $site];
}
test('owner can run anything', function () {
    [$user, $site] = makeUserWithRole('owner');
    $gate = new RemoteCliPermissions;

    expect($gate->can($user, $site, RiskLevel::Read))->toBeTrue();
    expect($gate->can($user, $site, RiskLevel::MutatingRecoverable))->toBeTrue();
    expect($gate->can($user, $site, RiskLevel::Destructive))->toBeTrue();
});
test('admin can run anything', function () {
    [$user, $site] = makeUserWithRole('admin');
    $gate = new RemoteCliPermissions;

    expect($gate->can($user, $site, RiskLevel::Read))->toBeTrue();
    expect($gate->can($user, $site, RiskLevel::MutatingRecoverable))->toBeTrue();
    expect($gate->can($user, $site, RiskLevel::Destructive))->toBeTrue();
});
test('member can read and recoverable but not destructive', function () {
    [$user, $site] = makeUserWithRole('member');
    $gate = new RemoteCliPermissions;

    expect($gate->can($user, $site, RiskLevel::Read))->toBeTrue();
    expect($gate->can($user, $site, RiskLevel::MutatingRecoverable))->toBeTrue();
    expect($gate->can($user, $site, RiskLevel::Destructive))->toBeFalse();
});
test('non member can run nothing', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);
    $gate = new RemoteCliPermissions;

    expect($gate->can($stranger, $site, RiskLevel::Read))->toBeFalse();
    expect($gate->can($stranger, $site, RiskLevel::MutatingRecoverable))->toBeFalse();
    expect($gate->can($stranger, $site, RiskLevel::Destructive))->toBeFalse();
});
test('project viewer can read but cannot mutate', function () {
    $owner = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);

    $viewer = User::factory()->create();
    $org->users()->attach($viewer->id, ['role' => 'member']);

    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->create([
        'user_id' => $viewer->id,
        'role' => WorkspaceMember::ROLE_VIEWER,
    ]);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
    ]);
    $gate = new RemoteCliPermissions;

    expect($viewer->can('view', $site))->toBeTrue();
    expect($viewer->can('update', $site))->toBeFalse();
    expect($gate->can($viewer, $site, RiskLevel::Read))->toBeTrue();
    expect($gate->can($viewer, $site, RiskLevel::MutatingRecoverable))->toBeFalse();
    expect($gate->can($viewer, $site, RiskLevel::Destructive))->toBeFalse();
});
test('system run with no user bypasses gate', function () {
    [, $site] = makeUserWithRole('member');
    $gate = new RemoteCliPermissions;

    expect($gate->can(null, $site, RiskLevel::Read))->toBeTrue();
    expect($gate->can(null, $site, RiskLevel::MutatingRecoverable))->toBeTrue();
    expect($gate->can(null, $site, RiskLevel::Destructive))->toBeTrue();
});
test('ensure can throws with command in message', function () {
    [$user, $site] = makeUserWithRole('member');
    $gate = new RemoteCliPermissions;

    try {
        $gate->ensureCan($user, $site, RiskLevel::Destructive, 'db drop');
        $this->fail('Expected RemoteCliPermissionDeniedException');
    } catch (RemoteCliPermissionDeniedException $e) {
        expect($e->risk)->toBe(RiskLevel::Destructive);
        expect($e->command)->toBe('db drop');
        $this->assertStringContainsString('db drop', $e->getMessage());
    }
});
