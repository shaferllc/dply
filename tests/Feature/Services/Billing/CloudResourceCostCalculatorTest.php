<?php

namespace Tests\Feature\Services\Billing\CloudResourceCostCalculatorTest;

use App\Models\CloudDatabase;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\CloudResourceCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('subscription.standard.cloud_markup_percent', 40);
    Config::set('subscription.standard.cloud_container_cents', [
        'small' => 500,
        'medium' => 1000,
        'large' => 2000,
    ]);
    Config::set('subscription.standard.cloud_database_cents', [
        'small' => 1500,
        'medium' => 3000,
        'large' => 6000,
    ]);
    Config::set('subscription.standard.cloud_bucket_cents', 500);

    $this->calculator = app(CloudResourceCostCalculator::class);
});

test('an empty collection costs nothing', function () {
    expect($this->calculator->subtotalCents(collect()))->toBe(0);
});

test('a small single-instance container bills the marked-up rate', function () {
    $site = makeCloudSite('small', 1);

    // $5 raw × 1.4 = $7
    expect($this->calculator->subtotalCents(collect([$site])))->toBe(700);
});

test('container cost scales with size tier and instance count', function () {
    $site = makeCloudSite('large', 3);

    // $20 raw × 1.4 = $28 × 3 instances = $84
    expect($this->calculator->subtotalCents(collect([$site])))->toBe(8400);
});

test('background workers add their own marked-up container cost', function () {
    $site = makeCloudSite('small', 1);
    CloudWorker::factory()->active()->create(['site_id' => $site->id, 'size' => 'medium', 'instance_count' => 2]);

    // container $7 + worker ($10 × 1.4 = $14) × 2 = $7 + $28 = $35
    expect($this->calculator->subtotalCents(collect([$site])))->toBe(3500);
});

test('attached managed databases bill once with markup', function () {
    $site = makeCloudSite('small', 1);
    $db = CloudDatabase::factory()->active()->create([
        'organization_id' => $site->organization_id,
        'size' => 'medium',
    ]);
    $db->sites()->attach($site->id, ['env_prefix' => 'DB']);

    // container $7 + database ($30 × 1.4 = $42) = $49
    expect($this->calculator->subtotalCents(collect([$site])))->toBe(4900);
});

test('a database shared across two apps is only billed once', function () {
    $org = Organization::factory()->create();
    $a = makeCloudSite('small', 1, $org);
    $b = makeCloudSite('small', 1, $org);
    $db = CloudDatabase::factory()->active()->create(['organization_id' => $org->id, 'size' => 'small']);
    $db->sites()->attach([$a->id, $b->id], ['env_prefix' => 'DB']);

    // 2 containers × $7 + 1 shared database ($15 × 1.4 = $21) = $14 + $21 = $35
    expect($this->calculator->subtotalCents(collect([$a, $b])))->toBe(3500);
});

test('provisioning workers and databases are still billed but failed ones are not', function () {
    $site = makeCloudSite('small', 1);
    CloudWorker::factory()->create(['site_id' => $site->id, 'size' => 'small', 'status' => CloudWorker::STATUS_PROVISIONING]);
    CloudWorker::factory()->create(['site_id' => $site->id, 'size' => 'small', 'status' => CloudWorker::STATUS_FAILED]);

    // container $7 + one billable (provisioning) small worker $7 = $14
    expect($this->calculator->subtotalCents(collect([$site])))->toBe(1400);
});

function makeCloudSite(string $sizeTier, int $instances, ?Organization $org = null): Site
{
    $org ??= Organization::factory()->create();

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'container_backend' => 'dply_cloud',
        'meta' => ['container' => ['size_tier' => $sizeTier, 'instance_count' => $instances]],
    ]);
}
