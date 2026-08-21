<?php

namespace Tests\Feature\Api\SiteUptimeApiTest;

use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: string}
 */
function uptimeSiteWithToken(array $abilities = ['sites.read', 'sites.write']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$site, $plaintext];
}

function makeMonitor(Site $site, array $attributes = []): SiteUptimeMonitor
{
    return SiteUptimeMonitor::query()->create(array_merge([
        'site_id' => $site->id,
        'label' => 'Homepage',
        'check_type' => SiteUptimeMonitor::CHECK_HTTPS,
        'path' => '/',
        'probe_region' => 'eu-amsterdam',
        'sort_order' => 0,
    ], $attributes));
}

it('reports each monitor with its check type and current state', function () {
    [$site, $token] = uptimeSiteWithToken();
    makeMonitor($site, ['label' => 'Checked', 'last_ok' => true, 'last_checked_at' => now(), 'last_http_status' => 200, 'last_latency_ms' => 120]);
    makeMonitor($site, ['label' => 'Fresh', 'path' => '/api/health', 'sort_order' => 1]);

    $response = $this->withToken($token)->getJson("/api/v1/sites/{$site->slug}/uptime");
    $response->assertOk();

    // Sites seed default monitors on create, so address rows by label.
    $rows = collect($response->json('data'))->keyBy('label');

    expect($rows['Checked']['check_type'])->toBe('https')
        ->and($rows['Checked']['status'])->toBe('up')
        ->and($rows['Checked']['http_status'])->toBe(200)
        ->and($rows['Checked']['latency_ms'])->toBe(120)
        // Never probed yet is not the same as down.
        ->and($rows['Fresh']['status'])->toBe('unchecked');
});

it('summarises uptime and incidents per monitor', function () {
    [$site, $token] = uptimeSiteWithToken();
    $monitor = makeMonitor($site);

    $response = $this->withToken($token)->getJson("/api/v1/sites/{$site->slug}/uptime/history");
    $response->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $monitor->id);

    expect($row)->not->toBeNull()
        ->and($row['has_data'])->toBeFalse()
        ->and($row['uptime']['24h'])->toBeNull()
        ->and($row['incidents'])->toBe([]);
});

it('narrows history to one monitor', function () {
    [$site, $token] = uptimeSiteWithToken();
    makeMonitor($site);
    $second = makeMonitor($site, ['label' => 'API', 'sort_order' => 1]);

    $this->withToken($token)
        ->getJson("/api/v1/sites/{$site->slug}/uptime/history?monitor={$second->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'API');
});

it('queues a probe for one monitor or all of them', function () {
    Queue::fake();
    [$site, $token] = uptimeSiteWithToken();
    $monitor = makeMonitor($site);
    $total = SiteUptimeMonitor::query()->where('site_id', $site->id)->count();

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/uptime/check", ['id' => $monitor->id])
        ->assertStatus(202)
        ->assertJsonPath('data.queued', 1);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/uptime/check", ['all' => true])
        ->assertStatus(202)
        ->assertJsonPath('data.queued', $total);

    // The job is ShouldBeUnique per monitor, so re-checking the one already
    // queued above collapses into it: one job per distinct monitor, not four.
    Queue::assertPushed(RunSiteUptimeMonitorCheckJob::class, $total);
});

it('refuses a monitor id that belongs to another site', function () {
    Queue::fake();
    [$site, $token] = uptimeSiteWithToken();
    [$otherSite] = uptimeSiteWithToken();
    $foreign = makeMonitor($otherSite);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/uptime/check", ['id' => $foreign->id])
        ->assertNotFound();

    Queue::assertNotPushed(RunSiteUptimeMonitorCheckJob::class);
});

it('requires an id or all=1', function () {
    [$site, $token] = uptimeSiteWithToken();
    makeMonitor($site);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/uptime/check", [])
        ->assertStatus(422);
});

it('needs sites.write to probe, sites.read to look', function () {
    [$site, $readOnly] = uptimeSiteWithToken(['sites.read']);
    $monitor = makeMonitor($site);

    $this->withToken($readOnly)
        ->getJson("/api/v1/sites/{$site->slug}/uptime/history")
        ->assertOk();

    $this->withToken($readOnly)
        ->postJson("/api/v1/sites/{$site->slug}/uptime/check", ['id' => $monitor->id])
        ->assertForbidden();
});
