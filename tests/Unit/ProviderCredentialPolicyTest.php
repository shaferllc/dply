<?php

namespace Tests\Unit\ProviderCredentialPolicyTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Policies\ProviderCredentialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->policy = new ProviderCredentialPolicy;
});

test('delete allows org owner', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect($this->policy->delete($user, $credential))->toBeTrue();
});

test('delete allows org admin', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach($admin->id, ['role' => 'admin']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);

    expect($this->policy->delete($admin, $credential))->toBeTrue();
});

test('delete denies ordinary org member', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach($member->id, ['role' => 'member']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
    ]);

    expect($this->policy->delete($member, $credential))->toBeFalse();
});

test('delete still allows the owner of a personal credential', function () {
    $user = User::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => null,
    ]);

    expect($this->policy->delete($user, $credential))->toBeTrue();
});
