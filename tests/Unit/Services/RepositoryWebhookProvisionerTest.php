<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeploySyncGroup;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\Sites\SiteDeploySyncCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepositoryWebhookProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_enable_creates_github_hook_and_persists_meta(): void
    {
        Http::fake(function () {
            return Http::response(['id' => 9001], 201);
        });

        $org = Organization::factory()->create();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'git_repository_url' => 'https://github.com/acme/demo.git',
            'webhook_secret' => 'whsec_test',
        ]);
        $site->mergeRepositoryMeta(['git_provider_kind' => 'github']);
        $site->save();

        $user = User::factory()->create();
        $account = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'github',
            'provider_id' => '123',
            'access_token' => 'gho_testtoken',
        ]);

        $provisioner = new RepositoryWebhookProvisioner(new SiteDeploySyncCoordinator);
        $result = $provisioner->enable($site->fresh(), $account);

        $this->assertTrue($result['ok']);
        $site->refresh();
        $hook = $site->repositoryMeta()['provider_hook'] ?? null;
        $this->assertIsArray($hook);
        $this->assertSame('9001', (string) $hook['id']);
        $this->assertSame('github', $hook['provider']);
        $this->assertSame((string) $account->id, $hook['account_id']);

        Http::assertSentCount(1);
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);
        /** @var Request $request */
        $request = $recorded[0][0];
        $this->assertSame('https://api.github.com/repos/acme/demo/hooks', $request->url());
        $data = $request->data();
        $this->assertSame($site->deployHookUrl(), $data['config']['url'] ?? null);
        $this->assertSame('whsec_test', $data['config']['secret'] ?? null);
    }

    public function test_follower_in_sync_group_cannot_register_provider_hook(): void
    {
        $org = Organization::factory()->create();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $leader = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'git_repository_url' => 'https://github.com/acme/demo.git',
        ]);
        $follower = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'git_repository_url' => 'https://github.com/acme/demo.git',
        ]);

        $group = SiteDeploySyncGroup::query()->create([
            'organization_id' => $org->id,
            'name' => 'G',
            'leader_site_id' => $leader->id,
        ]);
        $group->sites()->attach($leader->id, ['id' => (string) Str::ulid(), 'sort_order' => 0]);
        $group->sites()->attach($follower->id, ['id' => (string) Str::ulid(), 'sort_order' => 1]);

        $user = User::factory()->create();
        $account = SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'github',
            'provider_id' => '124',
            'access_token' => 'gho_testtoken',
        ]);

        $follower->mergeRepositoryMeta(['git_provider_kind' => 'github']);
        $follower->save();

        $provisioner = new RepositoryWebhookProvisioner(new SiteDeploySyncCoordinator);
        $result = $provisioner->enable($follower->fresh(), $account);

        $this->assertFalse($result['ok']);
    }
}
