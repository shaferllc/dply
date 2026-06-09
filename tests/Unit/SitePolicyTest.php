<?php

namespace Tests\Unit\SitePolicyTest;

use App\Models\Organization;
use App\Models\User;
use App\Policies\SitePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->policy = new SitePolicy;
});

test('create denies without current organization', function () {
    $user = User::factory()->create();
    expect($user->currentOrganization())->toBeNull();

    expect($this->policy->create($user))->toBeFalse();
});

test('create allows when org under limit', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
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
