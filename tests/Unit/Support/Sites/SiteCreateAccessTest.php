<?php

use App\Enums\QuotaSurface;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
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

test('site create access blocks leftover functions hosts', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    expect(SiteCreateAccess::canCreate($server, $user))->toBeFalse()
        ->and(SiteCreateAccess::blockedBy($server, $user))->toBe(SiteCreateAccess::BLOCKED_BY_HOST);
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

test('managed-product apps no longer consume the machine-site ceiling', function () {
    config(['subscription.standard.plans' => [
        'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1, 'max_sites' => 1, 'max_cloud_apps' => 1, 'max_edge_apps' => 3],
        'business' => ['label' => 'Business', 'price_cents' => 3900, 'max_servers' => null, 'max_sites' => null, 'max_cloud_apps' => null, 'max_edge_apps' => null],
    ]]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    // The VM the operator is looking at — deliberately empty.
    $vm = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $edgeHost = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $functionsHost = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    Site::factory()->count(2)->create(['organization_id' => $org->id, 'server_id' => $edgeHost->id]);
    Site::factory()->create(['organization_id' => $org->id, 'server_id' => $functionsHost->id]);

    // Leftover managed-product apps must not consume the VM site ceiling.
    expect($org->quotaUsageBySurface())->toBe([
        'site' => 0,
    ])->and(SiteCreateAccess::canCreate($vm, $user))->toBeTrue();
});

test('quota block names the surface and says where the usage is', function () {
    config(['subscription.standard.plans' => [
        'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1, 'max_sites' => 1, 'max_cloud_apps' => 1, 'max_edge_apps' => 3],
        'business' => ['label' => 'Business', 'price_cents' => 3900, 'max_servers' => null, 'max_sites' => null, 'max_cloud_apps' => null, 'max_edge_apps' => null],
    ]]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $emptyVm = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $otherVm = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    Site::factory()->create(['organization_id' => $org->id, 'server_id' => $otherVm->id]);

    $access = SiteCreateAccess::assess($emptyVm, $user);

    expect($access['can_create'])->toBeFalse()
        ->and($access['blocked_by'])->toBe(SiteCreateAccess::BLOCKED_BY_QUOTA)
        ->and($access['blocked_reason'])->toContain('site limit (1 / 1)')
        ->and($access['quota']['surface'])->toBe('site')
        ->and($access['quota']['noun'])->toBe('site')
        ->and($access['quota']['used'])->toBe(1)
        ->and($access['quota']['limit'])->toBe(1)
        // The whole point: none of the usage is on the server being viewed.
        ->and($access['quota']['elsewhere'])->toBe(1)
        ->and($access['quota']['index_route'])->toBe('sites.index');
});

test('each managed surface blocks on its own ceiling', function () {
    config(['subscription.standard.plans' => [
        'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1, 'max_sites' => 1, 'max_cloud_apps' => 1, 'max_edge_apps' => 3],
        'business' => ['label' => 'Business', 'price_cents' => 3900, 'max_servers' => null, 'max_sites' => null, 'max_cloud_apps' => null, 'max_edge_apps' => null],
    ]]);

    $org = Organization::factory()->create();

    $edgeHost = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    Site::factory()->count(3)->create(['organization_id' => $org->id, 'server_id' => $edgeHost->id]);

    // Edge is full at 3; every other surface is untouched.

        expect($org->canCreateOnSurface(QuotaSurface::Site))->toBeTrue();
});
