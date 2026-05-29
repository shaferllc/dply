<?php

namespace Tests\Unit\Services\SiteCloneDestinationValidatorTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

test('clone allowed when the org has site headroom on its plan', function () {
    // Sites are capped per flat plan (resolved from billable server count).
    // An org with two billable servers lands on Starter (10 sites), so a
    // single existing site leaves plenty of room to clone.
    config(['subscription.standard.min_billable_age_days' => 0]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => FAKE_SSH_KEY,
    ]);
    $dest = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => FAKE_SSH_KEY,
    ]);

    $source = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    expect($org->fresh()->currentSubscriptionPlan()['key'])->toBe('starter');
    expect($org->fresh()->canCreateSite())->toBeTrue();
    expect($org->fresh()->maxSites())->toBe(10);
});
