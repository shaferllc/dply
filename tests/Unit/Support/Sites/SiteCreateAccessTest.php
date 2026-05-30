<?php

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\Sites\SiteCreateAccess;

test('site create access allows ready server for org owner', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $access = SiteCreateAccess::assess($server, $user);

    expect($access['blocked_reason'])->toBe('')
        ->and($access['can_create'])->toBeTrue()
        ->and(SiteCreateAccess::canCreate($server, $user))->toBeTrue();
});

test('site create access blocks deployer role', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect(SiteCreateAccess::canCreate($server, $user))->toBeFalse()
        ->and(SiteCreateAccess::blockedReason($server, $user))->toContain('deployer');
});
