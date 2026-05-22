<?php


namespace Tests\Feature\Services\Billing\OrganizationBillingStateComputerTest;
use App\Models\FunctionAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Services\Billing\OrganizationBillingStateComputer;
use Illuminate\Support\Facades\Config;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Keep the existing assertions sharp by setting the age cutoff to zero
    // for these scenarios — a dedicated test below exercises the threshold
    // separately. Test factories create servers with `created_at = now()`,
    // which would otherwise be excluded by the default 1-day filter.
    Config::set('subscription.standard.min_billable_age_days', 0);
    $this->computer = app(OrganizationBillingStateComputer::class);
});

test('empty org returns base only', function () {
    $org = Organization::factory()->create();

    $state = $this->computer->compute($org);

    expect($state->serverCount())->toBe(0);

    // $15 base + no servers, no credit.
    expect($state->monthlyTotalCents)->toBe(1500);
});

test('classifies each ready server into its tier', function () {
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

    // $15 base + ($10 + $20 + $2) = $47 = 4700 cents
    expect($state->monthlyTotalCents)->toBe(4700);
});

test('excludes non ready servers', function () {
    $org = Organization::factory()->create();
    makeServerWithSpecs($org, status: Server::STATUS_PROVISIONING, cpuCount: 16, memMb: 32768);
    makeServerWithSpecs($org, status: Server::STATUS_ERROR, cpuCount: 8, memMb: 16384);
    makeServerWithSpecs($org, status: Server::STATUS_DISCONNECTED, cpuCount: 4, memMb: 8192);
    makeServerWithSpecs($org, status: Server::STATUS_PENDING, cpuCount: 4, memMb: 8192);
    makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 2, memMb: 4096);

    // S, only billable
    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(1);
    expect($state->tierQuantities['s'])->toBe(1);

    // $15 base + $5 S server = $20
    expect($state->monthlyTotalCents)->toBe(2000);
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

    // $15 base + $2 XS server = $17
    expect($state->monthlyTotalCents)->toBe(1700);
});

test('ignores servers from other organizations', function () {
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    makeServerWithSpecs($otherOrg, status: Server::STATUS_READY, cpuCount: 16, memMb: 32768);

    $state = $this->computer->compute($org->fresh());

    expect($state->serverCount())->toBe(0);
    expect($state->monthlyTotalCents)->toBe(1500);
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

    // $15 base + $10 M (the mature one only) = $25
    expect($state->monthlyTotalCents)->toBe(2500);
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
    expect($state->monthlyTotalCents)->toBe(1500);
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

    // $15 base + 3 × $2 = $21
    expect($state->monthlyTotalCents)->toBe(2100);
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