<?php

namespace Tests\Feature\Api;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteGitDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SiteDeployApiTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSiteInOrg(Organization $org): Site
    {
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'git_repository_url' => 'git@github.com:org/repo.git',
        ]);
    }

    /**
     * @return array{0: User, 1: Organization, 2: string}
     */
    protected function createTokenForOrg(Organization $org, ?array $abilities = null, ?array $allowedIps = null): array
    {
        $user = $org->users()->first();
        if (! $user) {
            $user = User::factory()->create();
            $org->users()->attach($user->id, ['role' => 'owner']);
        }
        ['plaintext' => $plain] = ApiToken::createToken(
            $user,
            $org,
            'test',
            null,
            $abilities ?? ['sites.read', 'sites.deploy', 'servers.read'],
            $allowedIps
        );

        return [$user, $org, $plain];
    }

    public function test_deployments_list_returns_403_for_site_in_other_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $siteA = $this->makeSiteInOrg($orgA);
        [, , $plainB] = $this->createTokenForOrg($orgB);

        $this->getJson('/api/v1/sites/'.$siteA->id.'/deployments', [
            'Authorization' => 'Bearer '.$plainB,
        ])->assertForbidden();
    }

    public function test_idempotent_deploy_returns_409_while_inflight(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $site = $this->makeSiteInOrg($org);
        [, , $plain] = $this->createTokenForOrg($org);

        $headers = [
            'Authorization' => 'Bearer '.$plain,
            'Idempotency-Key' => 'same-key',
        ];

        $this->postJson('/api/v1/sites/'.$site->id.'/deploy', [], $headers)->assertAccepted();
        $this->postJson('/api/v1/sites/'.$site->id.'/deploy', [], $headers)->assertStatus(409);

        Queue::assertPushed(RunSiteDeploymentJob::class, 1);
    }

    public function test_sync_deploy_with_idempotency_key_caches_result(): void
    {
        $this->mock(SiteGitDeployer::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn(['output' => 'ok', 'sha' => 'abc123']);
        });

        $org = Organization::factory()->create();
        $site = $this->makeSiteInOrg($org);
        [, , $plain] = $this->createTokenForOrg($org);

        $headers = [
            'Authorization' => 'Bearer '.$plain,
            'Idempotency-Key' => 'idem-1',
        ];

        $r1 = $this->postJson('/api/v1/sites/'.$site->id.'/deploy', ['sync' => true], $headers);
        $r1->assertOk();
        $r2 = $this->postJson('/api/v1/sites/'.$site->id.'/deploy', ['sync' => true], $headers);
        $r2->assertOk();
        $this->assertSame($r1->json(), $r2->json());
    }

    public function test_api_token_ip_allow_list_blocks_wrong_ip(): void
    {
        $org = Organization::factory()->create();
        $this->makeSiteInOrg($org);
        [, , $plain] = $this->createTokenForOrg($org, ['sites.read'], ['198.51.100.10']);

        $this->getJson('/api/v1/sites', [
            'Authorization' => 'Bearer '.$plain,
        ])->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
