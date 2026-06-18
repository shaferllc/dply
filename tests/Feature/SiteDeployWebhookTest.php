<?php

namespace Tests\Feature\SiteDeployWebhookTest;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploySyncGroup;
use App\Models\WebhookDeliveryLog;
use App\Support\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSiteWithSecret(string $secret = 'whsec_test_value'): Site
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'webhook_secret' => $secret,
        'git_repository_url' => 'git@github.com:org/repo.git',
    ]);

    return $site->fresh();
}

test('rejects when secret missing', function () {
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'webhook_secret' => null,
    ]);

    $this->postJson(route('hooks.site.deploy', $site))->assertStatus(400);
    expect(WebhookDeliveryLog::query()->where('site_id', $site->id)->count())->toBe(1);
});

test('rejects invalid signature', function () {
    $site = makeSiteWithSecret();

    $this->postJson(route('hooks.site.deploy', $site), [], [
        'X-Dply-Signature' => 'sha256=deadbeef',
    ])->assertStatus(401);
});

test('accepts legacy body hmac and queues deploy', function () {
    Queue::fake();
    $site = makeSiteWithSecret('plain_secret');
    $body = '';
    $sig = WebhookSignature::expectedLegacyHeader('plain_secret', $body);

    $this->call('POST', route('hooks.site.deploy', $site), [], [], [], [
        'HTTP_X_DPLY_SIGNATURE' => $sig,
    ], $body)->assertStatus(202);

    Queue::assertPushed(RunSiteDeploymentJob::class);
});

test('accepts timestamped signature', function () {
    Queue::fake();
    $site = makeSiteWithSecret('plain_secret');
    $body = '{"ref":"main"}';
    $ts = time();
    $sig = WebhookSignature::expectedTimestampedHeader('plain_secret', $ts, $body);

    $this->call('POST', route('hooks.site.deploy', $site), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DPLY_SIGNATURE' => $sig,
        'HTTP_X_DPLY_TIMESTAMP' => (string) $ts,
    ], $body)->assertStatus(202);

    Queue::assertPushed(RunSiteDeploymentJob::class);
});

test('rejects disallowed ip', function () {
    $site = makeSiteWithSecret('plain_secret');
    $site->update(['webhook_allowed_ips' => ['203.0.113.50']]);

    $body = '';
    $sig = WebhookSignature::expectedLegacyHeader('plain_secret', $body);

    $this->call('POST', route('hooks.site.deploy', $site), [], [], [], [
        'HTTP_X_DPLY_SIGNATURE' => $sig,
    ], $body)->assertStatus(403);
});

test('accepts github hub signature 256 for push', function () {
    Queue::fake();
    $site = makeSiteWithSecret('plain_secret');
    $site->update(['git_branch' => 'main']);
    $body = '{"ref":"refs/heads/main"}';
    $sig = 'sha256='.hash_hmac('sha256', $body, 'plain_secret');

    $this->call('POST', route('hooks.site.deploy', $site), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $sig,
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $body)->assertStatus(202);

    Queue::assertPushed(RunSiteDeploymentJob::class);
});

test('github ping returns 200 without queue', function () {
    Queue::fake();
    $site = makeSiteWithSecret('plain_secret');
    $body = '{}';
    $sig = 'sha256='.hash_hmac('sha256', $body, 'plain_secret');

    $this->call('POST', route('hooks.site.deploy', $site), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $sig,
        'HTTP_X_GITHUB_EVENT' => 'ping',
    ], $body)->assertStatus(200);

    Queue::assertNotPushed(RunSiteDeploymentJob::class);
});

test('leader github push queues peer sync deploys', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $leader = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'webhook_secret' => 'plain_secret',
        'git_branch' => 'main',
        'git_repository_url' => 'git@github.com:org/repo.git',
    ]);
    $peer = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'webhook_secret' => 'peer_secret',
        'git_branch' => 'main',
        'git_repository_url' => 'git@github.com:org/repo.git',
    ]);

    $group = SiteDeploySyncGroup::query()->create([
        'organization_id' => $org->id,
        'name' => 'Sync',
        'leader_site_id' => $leader->id,
    ]);
    $group->sites()->attach($leader->id, ['id' => (string) Str::ulid(), 'sort_order' => 0]);
    $group->sites()->attach($peer->id, ['id' => (string) Str::ulid(), 'sort_order' => 1]);

    $body = '{"ref":"refs/heads/main"}';
    $sig = 'sha256='.hash_hmac('sha256', $body, 'plain_secret');

    $this->call('POST', route('hooks.site.deploy', $leader), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $sig,
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $body)->assertStatus(202);

    Queue::assertPushed(RunSiteDeploymentJob::class, 2);
    Queue::assertPushed(RunSiteDeploymentJob::class, function (RunSiteDeploymentJob $job) use ($leader, $peer) {
        if ($job->site->is($leader)) {
            return $job->trigger === SiteDeployment::TRIGGER_WEBHOOK;
        }
        if ($job->site->is($peer)) {
            return $job->trigger === SiteDeployment::TRIGGER_SYNC_PEER;
        }

        return false;
    });
});

test('non leader github push does not queue peer sync deploys', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $leader = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'webhook_secret' => 'leader_secret',
        'git_branch' => 'main',
        'git_repository_url' => 'git@github.com:org/repo.git',
    ]);
    $peer = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'webhook_secret' => 'plain_secret',
        'git_branch' => 'main',
        'git_repository_url' => 'git@github.com:org/repo.git',
    ]);

    $group = SiteDeploySyncGroup::query()->create([
        'organization_id' => $org->id,
        'name' => 'Sync',
        'leader_site_id' => $leader->id,
    ]);
    $group->sites()->attach($leader->id, ['id' => (string) Str::ulid(), 'sort_order' => 0]);
    $group->sites()->attach($peer->id, ['id' => (string) Str::ulid(), 'sort_order' => 1]);

    $body = '{"ref":"refs/heads/main"}';
    $sig = 'sha256='.hash_hmac('sha256', $body, 'plain_secret');

    $this->call('POST', route('hooks.site.deploy', $peer), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $sig,
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $body)->assertStatus(202);

    Queue::assertPushed(RunSiteDeploymentJob::class, 1);
    Queue::assertPushed(RunSiteDeploymentJob::class, function (RunSiteDeploymentJob $job) use ($peer) {
        return $job->site->is($peer) && $job->trigger === SiteDeployment::TRIGGER_WEBHOOK;
    });
});
