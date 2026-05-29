<?php

namespace Tests\Feature\Services\Billing\OrganizationBillingStateComputerTest;

use App\Models\EdgeUsageSnapshot;
use App\Models\FunctionAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Services\Billing\OrganizationBillingStateComputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Keep the existing assertions sharp by setting the age cutoff to zero
    // for these scenarios — a dedicated test below exercises the threshold
    // separately. Test factories create servers with `created_at = now()`,
    // which would otherwise be excluded by the default 1-day filter.
    Config::set('subscription.standard.min_billable_age_days', 0);
    $this->computer = app(OrganizationBillingStateComputer::class);
});

test('empty org bills nothing on the free plan', function () {
    $org = Organization::factory()->create();

    $state = $this->computer->compute($org);

    expect($state->serverCount())->toBe(0);
    expect($state->planKey)->toBe('free');
    expect($state->monthlyTotalCents)->toBe(0);
});

test('classifies each ready server into its tier and resolves the plan by count', function () {
    $org = Organization::factory()->create();
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 4, memMb: 8192);
    // M
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 8, memMb: 16384);
    // L
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 1, memMb: 2048);

    // XS
    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(3);
    expect($state->tierQuantities['m'])->toBe(1);
    expect($state->tierQuantities['l'])->toBe(1);
    expect($state->tierQuantities['xs'])->toBe(1);

    // 3 servers → Starter ($9 flat), size no longer matters.
    expect($state->planKey)->toBe('starter');
    expect($state->monthlyTotalCents)->toBe(900);
});

test('excludes non ready servers', function () {
    $org = Organization::factory()->create();
    makeServerWithSpecs($org, status: Server::STATUS_PROVISIONING, cpuCount: 16, memMb: 32768);
    makeServerWithSpecs($org, status: Server::STATUS_ERROR, cpuCount: 8, memMb: 16384);
    makeServerWithSpecs($org, status: Server::STATUS_DISCONNECTED, cpuCount: 4, memMb: 8192);
    makeServerWithSpecs($org, status: Server::STATUS_PENDING, cpuCount: 4, memMb: 8192);
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 2, memMb: 4096);

    // S, only billable — 1 server → Free plan.
    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(1);
    expect($state->tierQuantities['s'])->toBe(1);
    expect($state->planKey)->toBe('free');
    expect($state->monthlyTotalCents)->toBe(0);
});

test('servers without metrics classify as xs', function () {
    $org = Organization::factory()->create();
    Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(1);
    expect($state->tierQuantities['xs'])->toBe(1);

    // A single server is the Free plan — $0.
    expect($state->planKey)->toBe('free');
    expect($state->monthlyTotalCents)->toBe(0);
});

test('a single server resolves to the free plan', function () {
    $org = Organization::factory()->create();
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 8, memMb: 16384);

    // One large server still falls in the Free ceiling (1 server).
    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(1);
    expect($state->planKey)->toBe('free');
    expect($state->monthlyTotalCents)->toBe(0);
});

test('a second server moves the org onto the starter plan', function () {
    $org = Organization::factory()->create();
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 1, memMb: 2048);
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 1, memMb: 2048);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(2);
    expect($state->planKey)->toBe('starter');
    expect($state->monthlyTotalCents)->toBe(900);
});

test('four servers move the org onto the pro plan', function () {
    $org = Organization::factory()->create();
    foreach (range(1, 4) as $i) {
        makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 1, memMb: 2048);
    }

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(4);
    expect($state->planKey)->toBe('pro');
    expect($state->monthlyTotalCents)->toBe(1900);
});

test('eleven servers move the org onto the unlimited business plan', function () {
    $org = Organization::factory()->create();
    foreach (range(1, 11) as $i) {
        makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 1, memMb: 2048);
    }

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(11);
    expect($state->planKey)->toBe('business');
    expect($state->monthlyTotalCents)->toBe(3900);
});

test('managed products are billed a la carte on top of the free plan', function () {
    Config::set('subscription.standard.edge_cents', 200);
    $org = Organization::factory()->create();
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 1, memMb: 2048);
    makeEdgeSite($org, Site::STATUS_EDGE_ACTIVE);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(1);
    expect($state->planKey)->toBe('free');
    expect($state->edgeCount)->toBe(1);

    // Free plan ($0) + $2 edge site = $2
    expect($state->monthlyTotalCents)->toBe(200);
});

test('ignores servers from other organizations', function () {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    makeServerWithSpecs($otherOrg, status: Server::STATUS_READY, cpuCount: 16, memMb: 32768);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(0);
    expect($state->planKey)->toBe('free');
    expect($state->monthlyTotalCents)->toBe(0);
});

test('excludes servers younger than min billable age', function () {
    Config::set('subscription.standard.min_billable_age_days', 1);

    $org = Organization::factory()->create();

    // Fresh server — created right now, under the 1-day grace window.
    $fresh = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'created_at' => now(),
    ]);
    ServerMetricSnapshot::query()->create([
        'server_id' => $fresh->id,
        'captured_at' => now(),
        'payload' => ['cpu_count' => 4, 'mem_total_kb' => 8 * 1024 * 1024],
    ]);

    // Mature server — created 2 days ago, well past the threshold.
    $mature = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(2),
    ]);
    ServerMetricSnapshot::query()->create([
        'server_id' => $mature->id,
        'captured_at' => now(),
        'payload' => ['cpu_count' => 4, 'mem_total_kb' => 8 * 1024 * 1024],
    ]);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(1);
    expect($state->tierQuantities['m'])->toBe(1);

    // Only the mature server counts → 1 server → Free plan.
    expect($state->planKey)->toBe('free');
    expect($state->monthlyTotalCents)->toBe(0);
});

test('age threshold is inclusive at the boundary', function () {
    Config::set('subscription.standard.min_billable_age_days', 1);

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        // Exactly 1 day old (slightly older to dodge clock jitter).
        'created_at' => now()->subDay()->subSecond(),
    ]);
    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['cpu_count' => 1, 'mem_total_kb' => 2 * 1024 * 1024],
    ]);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(1);
});

test('serverless host is not counted as a spec tier', function () {
    $org = Organization::factory()->create();

    // A DO Functions namespace host — ready, but a serverless host.
    Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $state = $this->computer->compute($org->fresh());

    // It would have classified as XS by null-fallback — must not.
    expect($state->serverCount())->toBe(0);
    expect($state->tierQuantities['xs'])->toBe(0);
    expect($state->monthlyTotalCents)->toBe(0);
});

test('dply cloud and edge hosts are not counted as spec tiers', function () {
    $org = Organization::factory()->create();

    Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);
    Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(0);
    expect($state->monthlyTotalCents)->toBe(0);
});

test('active dply cloud sites bill per app excluding previews', function () {
    Config::set('subscription.standard.cloud_cents', 500);
    $org = Organization::factory()->create();
    makeCloudSite($org, Site::STATUS_CONTAINER_ACTIVE);
    makeCloudSite($org, Site::STATUS_CONTAINER_ACTIVE, preview: true);

    $state = $this->computer->compute($org->fresh());

    expect($state->cloudCount)->toBe(1);
    expect($state->cloudSubtotalCents)->toBe(500);
    // Free plan ($0, no billable servers) + 1 cloud app ($5) = $5
    expect($state->monthlyTotalCents)->toBe(500);
});

test('active dply edge sites bill per site excluding previews', function () {
    Config::set('subscription.standard.edge_cents', 200);
    $org = Organization::factory()->create();
    makeEdgeSite($org, Site::STATUS_EDGE_ACTIVE);
    makeEdgeSite($org, Site::STATUS_EDGE_ACTIVE, preview: true);

    $state = $this->computer->compute($org->fresh());

    expect($state->edgeCount)->toBe(1);
    expect($state->edgeSubtotalCents)->toBe(200);
    // Free plan ($0) + 1 edge site ($2) = $2
    expect($state->monthlyTotalCents)->toBe(200);
});

test('edge delivery usage adds pass through subtotal when enabled', function () {
    Config::set('subscription.standard.edge_cents', 200);
    Config::set('dply.edge.usage_billing.enabled', true);
    Config::set('dply.edge.usage_billing.markup_percent', 0);
    Config::set('dply.edge.usage_billing.requests_cents_per_million', 100);
    Config::set('dply.edge.usage_billing.included_requests_per_site', 0);

    $org = Organization::factory()->create();
    $site = makeEdgeSite($org, Site::STATUS_EDGE_ACTIVE);

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $org->id,
        'site_id' => $site->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->startOfMonth()->toDateString(),
        'requests' => 2_000_000,
        'source' => EdgeUsageSnapshot::SOURCE_MANUAL,
    ]);

    $state = $this->computer->compute($org->fresh());

    expect($state->edgeCount)->toBe(1);
    expect($state->edgeSubtotalCents)->toBe(200);
    expect($state->edgeUsageSubtotalCents)->toBe(200);
    // Free plan ($0) + $2 edge site + $2 usage = $4
    expect($state->monthlyTotalCents)->toBe(400);
});

test('active serverless functions bill per function', function () {
    Config::set('subscription.standard.serverless_cents', 200);
    $org = Organization::factory()->create();
    makeFunctionSite($org, Site::STATUS_FUNCTIONS_ACTIVE);
    makeFunctionSite($org, Site::STATUS_FUNCTIONS_ACTIVE);
    makeFunctionSite($org, Site::STATUS_FUNCTIONS_ACTIVE);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverlessCount)->toBe(3);
    expect($state->serverlessSubtotalCents)->toBe(600);

    // Free plan ($0, serverless hosts aren't billable servers) + 3 × $2 = $6
    expect($state->monthlyTotalCents)->toBe(600);
});

test('non active functions are not billed', function () {
    Config::set('subscription.standard.serverless_cents', 200);
    $org = Organization::factory()->create();
    makeFunctionSite($org, Site::STATUS_FUNCTIONS_CONFIGURED);
    // pre-deploy
    makeFunctionSite($org, Site::STATUS_FUNCTIONS_ACTIVE);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverlessCount)->toBe(1);
});

test('each code action in a package is billed and sequences are not', function () {
    Config::set('subscription.standard.serverless_cents', 200);
    $org = Organization::factory()->create();
    $site = makeFunctionSite($org, Site::STATUS_FUNCTIONS_ACTIVE);

    foreach (['a', 'b', 'c'] as $name) {
        FunctionAction::query()->create([
            'site_id' => $site->id,
            'name' => $name,
            'kind' => FunctionAction::KIND_CODE,
        ]);
    }

    // A codeless sequence — composition is free, it must not be metered.
    FunctionAction::query()->create([
        'site_id' => $site->id,
        'name' => 'pipeline',
        'kind' => FunctionAction::KIND_SEQUENCE,
    ]);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverlessCount)->toBe(3);
    expect($state->serverlessSubtotalCents)->toBe(600);
});

test('an active function site with no enumerated actions still bills once', function () {
    Config::set('subscription.standard.serverless_cents', 200);
    $org = Organization::factory()->create();

    // No function_actions rows — the per-action meter must floor at one
    // so the bill never regresses below the per-Site model.
    makeFunctionSite($org, Site::STATUS_FUNCTIONS_ACTIVE);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverlessCount)->toBe(1);
});

function makeServerWithSpecs(Organization $org, string $status, int $cpuCount, int $memMb): Server
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => $status,
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => [
            'cpu_count' => $cpuCount,
            'mem_total_kb' => $memMb * 1024,
        ],
    ]);

    return $server;
}

function makeFunctionSite(Organization $org, string $status): Site
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => $status,
    ]);
}

function makeCloudSite(Organization $org, string $status, bool $preview = false): Site
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => $status,
        'container_backend' => 'dply_cloud',
        'meta' => $preview ? ['container' => ['preview_parent_site_id' => 'parent-id']] : [],
    ]);
}

function makeEdgeSite(Organization $org, string $status, bool $preview = false): Site
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => $status,
        'edge_backend' => 'dply_edge',
        'meta' => $preview ? ['edge' => ['preview_parent_site_id' => 'parent-id']] : [],
    ]);
}
