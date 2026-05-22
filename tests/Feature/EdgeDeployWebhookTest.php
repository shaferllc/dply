<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployEdgeSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EdgeDeployWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_url_dispatches_redeploy_job(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();

        $response = $this->postJson($site->edgeRedeployHookUrl(), ['image' => 'ghcr.io/acme/api:v2']);

        $response->assertOk()
            ->assertJson(['ok' => true, 'queued' => true, 'image' => 'ghcr.io/acme/api:v2']);
        Queue::assertPushed(RedeployEdgeSiteJob::class, function (RedeployEdgeSiteJob $job) use ($site): bool {
            return $job->siteId === $site->id && $job->newImage === 'ghcr.io/acme/api:v2';
        });
    }

    public function test_signed_url_without_image_redeploys_current_tag(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();

        $this->postJson($site->edgeRedeployHookUrl())->assertOk();

        Queue::assertPushed(RedeployEdgeSiteJob::class, function (RedeployEdgeSiteJob $job): bool {
            return $job->newImage === null;
        });
    }

    public function test_unsigned_url_is_rejected(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();

        $response = $this->postJson(route('hooks.edge.redeploy', ['site' => $site]));

        $response->assertForbidden()
            ->assertJson(['ok' => false, 'reason' => 'invalid_signature']);
        Queue::assertNotPushed(RedeployEdgeSiteJob::class);
    }

    public function test_non_container_site_returns_422(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Php,
        ]);

        $response = $this->postJson($site->edgeRedeployHookUrl());

        $response->assertStatus(422)
            ->assertJson(['ok' => false, 'reason' => 'not_a_container_site']);
        Queue::assertNotPushed(RedeployEdgeSiteJob::class);
    }

    public function test_ip_allow_list_blocks_disallowed_ips(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();
        $site->update(['webhook_allowed_ips' => '203.0.113.0/24']);

        // Default test IP is 127.0.0.1 which is NOT in the allow list.
        $response = $this->postJson($site->edgeRedeployHookUrl());

        $response->assertForbidden()
            ->assertJson(['ok' => false, 'reason' => 'ip_not_allowed']);
        Queue::assertNotPushed(RedeployEdgeSiteJob::class);
    }

    public function test_ip_allow_list_passes_for_listed_ips(): void
    {
        Queue::fake();
        $site = $this->makeContainerSite();
        $site->update(['webhook_allowed_ips' => '127.0.0.1']);

        $response = $this->postJson($site->edgeRedeployHookUrl());

        $response->assertOk();
        Queue::assertPushed(RedeployEdgeSiteJob::class);
    }

    private function makeContainerSite(): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'webhook_secret' => 'test-secret-token',
        ]);
    }
}
