<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployCloudSiteJob;
use App\Jobs\TeardownCloudSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Inbound GitHub webhook for source-mode cloud sites.
 * The operator pastes the URL + the site's webhook_secret into
 * their GitHub repo's webhook settings; GitHub then POSTs us
 * pull_request + push events. We route them:
 *   - PR opened/synchronize/reopened → spawn / refresh preview
 *   - PR closed → tear down preview
 *   - push to source branch → redeploy parent (production)
 */
class GithubCloudWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_request_opened_spawns_preview(): void
    {
        Queue::fake();
        $site = $this->makeSourceSite();

        $body = json_encode([
            'action' => 'opened',
            'pull_request' => ['number' => 42, 'head' => ['ref' => 'feature/login']],
        ], JSON_UNESCAPED_SLASHES);

        $response = $this->postWebhook($site, 'pull_request', $body);

        $response->assertOk()->assertJsonPath('queued', 'preview');
        $this->assertSame(1, Site::query()
            ->whereJsonContains('meta->container->preview_parent_site_id', $site->id)
            ->count());
    }

    public function test_pull_request_synchronize_returns_existing_preview(): void
    {
        Queue::fake();
        $site = $this->makeSourceSite();

        $body = json_encode([
            'action' => 'opened',
            'pull_request' => ['number' => 9, 'head' => ['ref' => 'feature/x']],
        ]);
        $this->postWebhook($site, 'pull_request', $body)->assertOk();

        // synchronize on the same branch should NOT spawn a duplicate
        $body2 = json_encode([
            'action' => 'synchronize',
            'pull_request' => ['number' => 9, 'head' => ['ref' => 'feature/x']],
        ]);
        $this->postWebhook($site, 'pull_request', $body2)->assertOk();

        $this->assertSame(1, Site::query()
            ->whereJsonContains('meta->container->preview_parent_site_id', $site->id)
            ->count());
    }

    public function test_pull_request_closed_tears_down_preview(): void
    {
        Queue::fake();
        $site = $this->makeSourceSite();

        // First open the PR to get a preview to tear down.
        $this->postWebhook($site, 'pull_request', json_encode([
            'action' => 'opened',
            'pull_request' => ['number' => 5, 'head' => ['ref' => 'feature/x']],
        ]))->assertOk();

        $response = $this->postWebhook($site, 'pull_request', json_encode([
            'action' => 'closed',
            'pull_request' => ['number' => 5, 'head' => ['ref' => 'feature/x']],
        ]));

        $response->assertOk()->assertJsonPath('queued', 'teardown');
        Queue::assertPushed(TeardownCloudSiteJob::class);
    }

    public function test_push_to_source_branch_queues_redeploy(): void
    {
        Queue::fake();
        $site = $this->makeSourceSite();

        $response = $this->postWebhook($site, 'push', json_encode([
            'ref' => 'refs/heads/main',
            'before' => 'aaaa',
            'after' => 'bbbb',
        ]));

        $response->assertOk()->assertJsonPath('queued', 'redeploy');
        Queue::assertPushed(RedeployCloudSiteJob::class, fn ($j) => $j->siteId === $site->id);
    }

    public function test_push_to_other_branch_is_no_op(): void
    {
        Queue::fake();
        $site = $this->makeSourceSite();

        $response = $this->postWebhook($site, 'push', json_encode([
            'ref' => 'refs/heads/feature/sidebar',
        ]));

        $response->assertOk()->assertJsonPath('queued', false);
        Queue::assertNotPushed(RedeployCloudSiteJob::class);
    }

    public function test_invalid_signature_returns_403(): void
    {
        $site = $this->makeSourceSite();
        $body = json_encode(['action' => 'opened', 'pull_request' => ['number' => 1, 'head' => ['ref' => 'feature/x']]]);

        $response = $this->postJson(
            route('hooks.cloud.github', $site),
            json_decode($body, true),
            [
                'X-Hub-Signature-256' => 'sha256=deadbeef',
                'X-GitHub-Event' => 'pull_request',
            ],
        );

        $response->assertStatus(403);
    }

    public function test_image_mode_site_returns_422(): void
    {
        $site = $this->makeSourceSite();
        // Strip source spec to make it image-mode-shaped.
        $site->update(['meta' => ['container' => []], 'container_image' => 'nginx:1']);

        $body = json_encode(['ref' => 'refs/heads/main']);
        $response = $this->postWebhook($site, 'push', $body);

        $response->assertStatus(422);
    }

    public function test_ping_event_returns_ok(): void
    {
        $site = $this->makeSourceSite();
        $response = $this->postWebhook($site, 'ping', json_encode(['zen' => 'Speak like a human.']));

        $response->assertOk()->assertJsonPath('event', 'ping');
    }

    public function test_unknown_event_is_ignored(): void
    {
        Queue::fake();
        $site = $this->makeSourceSite();
        $response = $this->postWebhook($site, 'star', json_encode(['action' => 'created']));

        $response->assertOk()->assertJsonPath('reason', 'event_ignored');
        Queue::assertNotPushed(RedeployCloudSiteJob::class);
        Queue::assertNotPushed(TeardownCloudSiteJob::class);
    }

    private function postWebhook(Site $site, string $event, string $body)
    {
        $signature = 'sha256='.hash_hmac('sha256', $body, (string) $site->webhook_secret);

        return $this->call(
            'POST',
            route('hooks.cloud.github', $site),
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITHUB_EVENT' => $event,
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body,
        );
    }

    private function makeSourceSite(): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'API service',
            'slug' => 'api-service',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'webhook_secret' => 'test-secret',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => [
                'container' => [
                    'source' => ['repo' => 'acme/api', 'branch' => 'main', 'deploy_on_push' => true],
                ],
            ],
        ]);
    }
}
