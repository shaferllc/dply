<?php

namespace Tests\Unit\OrganizationPolicyTest;

use App\Models\Organization;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->policy = new OrganizationPolicy;
});

test('view allows member', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    expect($this->policy->view($user, $org))->toBeTrue();
});

test('view denies non member', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    expect($this->policy->view($user, $org))->toBeFalse();
});

test('update allows admin', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);

    expect($this->policy->update($user, $org))->toBeTrue();
});

test('update denies member', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    expect($this->policy->update($user, $org))->toBeFalse();
});

test('delete allows only owner', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    expect($this->policy->delete($user, $org))->toBeTrue();
});

test('delete denies admin', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);

    expect($this->policy->delete($user, $org))->toBeFalse();
});
