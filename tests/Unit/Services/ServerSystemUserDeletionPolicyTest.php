<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerSystemUserDeletionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerSystemUserDeletionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocks_root_dply_and_deploy_users(): void
    {
        $policy = new ServerSystemUserDeletionPolicy;
        $org = Organization::factory()->create();
        $server = Server::factory()->for($org)->create(['ssh_user' => 'mydeploy']);

        config(['server_provision.deploy_ssh_user' => 'dply']);

        $this->assertNotNull($policy->deletionBlockedReason($server, 'root'));
        $this->assertNotNull($policy->deletionBlockedReason($server, 'dply'));
        $this->assertNotNull($policy->deletionBlockedReason($server, 'mydeploy'));
        $this->assertNotNull($policy->deletionBlockedReason($server, 'DPLY'));
    }

    public function test_blocks_when_site_still_uses_user(): void
    {
        $policy = new ServerSystemUserDeletionPolicy;
        $org = Organization::factory()->create();
        $server = Server::factory()->for($org)->create(['ssh_user' => 'dply-main']);
        $user = User::factory()->create();

        Site::factory()->for($server)->for($org)->for($user)->create([
            'php_fpm_user' => 'appu1',
        ]);

        $this->assertNotNull($policy->deletionBlockedReason($server, 'appu1'));
    }

    public function test_allows_when_unused_and_not_protected(): void
    {
        $policy = new ServerSystemUserDeletionPolicy;
        $org = Organization::factory()->create();
        $server = Server::factory()->for($org)->create(['ssh_user' => 'dply-main']);
        $user = User::factory()->create();

        Site::factory()->for($server)->for($org)->for($user)->create([
            'php_fpm_user' => 'other',
        ]);

        $this->assertNull($policy->deletionBlockedReason($server, 'orphan'));
    }

    public function test_site_counts_by_effective_user(): void
    {
        $policy = new ServerSystemUserDeletionPolicy;
        $org = Organization::factory()->create();
        $server = Server::factory()->for($org)->create(['ssh_user' => 'deploy']);
        $user = User::factory()->create();

        Site::factory()->for($server)->for($org)->for($user)->create(['php_fpm_user' => null]);
        Site::factory()->for($server)->for($org)->for($user)->create(['php_fpm_user' => 'appx']);

        $counts = $policy->siteCountsByUsername($server);

        $this->assertSame(1, $counts['deploy'] ?? 0);
        $this->assertSame(1, $counts['appx'] ?? 0);
    }
}
