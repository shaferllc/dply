<?php


namespace Tests\Unit\Services\SiteCloneDestinationValidatorTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\Clone\SiteCloneDestinationValidator;
use Illuminate\Support\Facades\Config;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

test('clone allowed now that site caps are retired', function () {
    // The legacy "trial site cap" check used to throw here. Under the
    // Standard pricing model, sites are uncapped — billing is per-server
    // and trial-state gating handles abuse rather than per-resource caps.
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

    expect($org->fresh()->canCreateSite())->toBeTrue();
    expect($org->fresh()->maxSites())->toBe(PHP_INT_MAX);
});