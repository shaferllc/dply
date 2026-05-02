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

    public function test_throws_when_org_at_site_limit(): void
    {
        Config::set('subscription.limits.sites_free', 1);

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
        $source->load('server');

        $this->assertFalse($org->fresh()->canCreateSite());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('Your organization has reached the site limit for the current plan.'));

        SiteCloneDestinationValidator::validateOrFail($user, $source, $dest, 'new.example.com');
    }
}
