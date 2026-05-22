<?php

declare(strict_types=1);

namespace Tests\Feature\GithubCloudWebhookTest;
use App\Enums\SiteType;
use App\Jobs\RedeployCloudSiteJob;
use App\Jobs\TeardownCloudSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('pull request opened spawns preview', function () {
    Queue::fake();
    $site = makeSourceSite();

    $body = json_encode([
        'action' => 'opened',
        'pull_request' => ['number' => 42, 'head' => ['ref' => 'feature/login']],
    ], JSON_UNESCAPED_SLASHES);

    $response = postWebhook($site, 'pull_request', $body);

    $response->assertOk()->assertJsonPath('queued', 'preview');
    expect(Site::query()
        ->whereJsonContains('meta->container->preview_parent_site_id', $site->id)
        ->count())->toBe(1);
});
test('pull request synchronize returns existing preview', function () {
    Queue::fake();
    $site = makeSourceSite();

    $body = json_encode([
        'action' => 'opened',
        'pull_request' => ['number' => 9, 'head' => ['ref' => 'feature/x']],
    ]);
    postWebhook($site, 'pull_request', $body)->assertOk();

    // synchronize on the same branch should NOT spawn a duplicate
    $body2 = json_encode([
        'action' => 'synchronize',
        'pull_request' => ['number' => 9, 'head' => ['ref' => 'feature/x']],
    ]);
    postWebhook($site, 'pull_request', $body2)->assertOk();

    expect(Site::query()
        ->whereJsonContains('meta->container->preview_parent_site_id', $site->id)
        ->count())->toBe(1);
});
test('pull request closed tears down preview', function () {
    Queue::fake();
    $site = makeSourceSite();

    // First open the PR to get a preview to tear down.
    postWebhook($site, 'pull_request', json_encode([
        'action' => 'opened',
        'pull_request' => ['number' => 5, 'head' => ['ref' => 'feature/x']],
    ]))->assertOk();

    $response = postWebhook($site, 'pull_request', json_encode([
        'action' => 'closed',
        'pull_request' => ['number' => 5, 'head' => ['ref' => 'feature/x']],
    ]));

    $response->assertOk()->assertJsonPath('queued', 'teardown');
    Queue::assertPushed(TeardownCloudSiteJob::class);
});
test('push to source branch queues redeploy', function () {
    Queue::fake();
    $site = makeSourceSite();

    $response = postWebhook($site, 'push', json_encode([
        'ref' => 'refs/heads/main',
        'before' => 'aaaa',
        'after' => 'bbbb',
    ]));

    $response->assertOk()->assertJsonPath('queued', 'redeploy');
    Queue::assertPushed(RedeployCloudSiteJob::class, fn ($j) => $j->siteId === $site->id);
});
test('push to other branch is no op', function () {
    Queue::fake();
    $site = makeSourceSite();

    $response = postWebhook($site, 'push', json_encode([
        'ref' => 'refs/heads/feature/sidebar',
    ]));

    $response->assertOk()->assertJsonPath('queued', false);
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('invalid signature returns 403', function () {
    $site = makeSourceSite();
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
});
test('image mode site returns 422', function () {
    $site = makeSourceSite();

    // Strip source spec to make it image-mode-shaped.
    $site->update(['meta' => ['container' => []], 'container_image' => 'nginx:1']);

    $body = json_encode(['ref' => 'refs/heads/main']);
    $response = postWebhook($site, 'push', $body);

    $response->assertStatus(422);
});
test('ping event returns ok', function () {
    $site = makeSourceSite();
    $response = postWebhook($site, 'ping', json_encode(['zen' => 'Speak like a human.']));

    $response->assertOk()->assertJsonPath('event', 'ping');
});
test('unknown event is ignored', function () {
    Queue::fake();
    $site = makeSourceSite();
    $response = postWebhook($site, 'star', json_encode(['action' => 'created']));

    $response->assertOk()->assertJsonPath('reason', 'event_ignored');
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
    Queue::assertNotPushed(TeardownCloudSiteJob::class);
});
function postWebhook(Site $site, string $event, string $body)
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
function makeSourceSite(): Site
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
