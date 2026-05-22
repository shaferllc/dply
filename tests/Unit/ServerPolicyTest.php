<?php


namespace Tests\Unit\ServerPolicyTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Policies\ServerPolicy;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->policy = new ServerPolicy;
});

test('view allows owner', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create(['user_id' => $user->id]);

    expect($this->policy->view($user, $server))->toBeTrue();
});

test('view allows organization member', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);

    expect($this->policy->view($user, $server))->toBeTrue();
});

test('view denies non member', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($otherUser->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $otherUser->id,
        'organization_id' => $org->id,
    ]);

    expect($this->policy->view($user, $server))->toBeFalse();
});

test('create denies without current organization', function () {
    $user = User::factory()->create();
    expect($user->currentOrganization())->toBeNull();

    expect($this->policy->create($user))->toBeFalse();
});

test('create allows with current organization', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    session(['current_organization_id' => $org->id]);

    expect($this->policy->create($user))->toBeTrue();
});

test('create denies deployer', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    session(['current_organization_id' => $org->id]);

    expect($this->policy->create($user))->toBeFalse();
});

test('delete org server requires admin', function () {
    $member = User::factory()->create();
    $admin = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);
    $org->users()->attach($admin->id, ['role' => 'admin']);
    $server = Server::factory()->create([
        'user_id' => $admin->id,
        'organization_id' => $org->id,
    ]);

    expect($this->policy->delete($member, $server))->toBeFalse();
    expect($this->policy->delete($admin, $server))->toBeTrue();
});

test('delete personal server allows owner', function () {
    $user = User::factory()->create();
    $server = Server::factory()->create(['user_id' => $user->id, 'organization_id' => null]);

    expect($this->policy->delete($user, $server))->toBeTrue();
});