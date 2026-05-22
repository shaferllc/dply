<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\Clone\SiteCloneDestinationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SiteCloneDestinationValidatorTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

    public function test_clone_allowed_now_that_site_caps_are_retired(): void
    {
        // The legacy "trial site cap" check used to throw here. Under the
        // Standard pricing model, sites are uncapped — billing is per-server
        // and trial-state gating handles abuse rather than per-resource caps.
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => self::FAKE_SSH_KEY,
        ]);
        $dest = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => self::FAKE_SSH_KEY,
        ]);

        $source = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->assertTrue($org->fresh()->canCreateSite());
        $this->assertSame(PHP_INT_MAX, $org->fresh()->maxSites());
    }
}
