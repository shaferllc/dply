<?php

declare(strict_types=1);

namespace Tests\Feature\CloudDeployWebhookTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\RedeployCloudSiteJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('signed url dispatches redeploy job', function () {
    Queue::fake();
    $site = makeContainerSite();

    $response = $this->postJson($site->cloudRedeployHookUrl(), ['image' => 'ghcr.io/acme/api:v2']);

    $response->assertOk()
        ->assertJson(['ok' => true, 'queued' => true, 'image' => 'ghcr.io/acme/api:v2']);
    Queue::assertPushed(RedeployCloudSiteJob::class, function (RedeployCloudSiteJob $job) use ($site): bool {
        return $job->siteId === $site->id && $job->newImage === 'ghcr.io/acme/api:v2';
    });
});
test('signed url without image redeploys current tag', function () {
    Queue::fake();
    $site = makeContainerSite();

    $this->postJson($site->cloudRedeployHookUrl())->assertOk();

    Queue::assertPushed(RedeployCloudSiteJob::class, function (RedeployCloudSiteJob $job): bool {
        return $job->newImage === null;
    });
});
test('unsigned url is rejected', function () {
    Queue::fake();
    $site = makeContainerSite();

    $response = $this->postJson(route('hooks.cloud.redeploy', ['site' => $site]));

    $response->assertForbidden()
        ->assertJson(['ok' => false, 'reason' => 'invalid_signature']);
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('non container site returns 422', function () {
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

    $response = $this->postJson($site->cloudRedeployHookUrl());

    $response->assertStatus(422)
        ->assertJson(['ok' => false, 'reason' => 'not_a_container_site']);
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('ip allow list blocks disallowed ips', function () {
    Queue::fake();
    $site = makeContainerSite();
    $site->update(['webhook_allowed_ips' => '203.0.113.0/24']);

    // Default test IP is 127.0.0.1 which is NOT in the allow list.
    $response = $this->postJson($site->cloudRedeployHookUrl());

    $response->assertForbidden()
        ->assertJson(['ok' => false, 'reason' => 'ip_not_allowed']);
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('ip allow list passes for listed ips', function () {
    Queue::fake();
    $site = makeContainerSite();
    $site->update(['webhook_allowed_ips' => '127.0.0.1']);

    $response = $this->postJson($site->cloudRedeployHookUrl());

    $response->assertOk();
    Queue::assertPushed(RedeployCloudSiteJob::class);
});
function makeContainerSite(): Site
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
