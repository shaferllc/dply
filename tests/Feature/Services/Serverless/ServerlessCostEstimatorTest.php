<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Serverless\ServerlessCostEstimatorTest;

use App\Models\Site;
use App\Services\Serverless\ServerlessCostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('function fee comes from subscription config', function () {
    config(['subscription.standard.serverless_cents' => 200]);

    expect((new ServerlessCostEstimator)->functionFee())->toBe(2.0);
});
test('cluster costs come from the pricing config', function () {
    $estimator = new ServerlessCostEstimator;

    expect($estimator->databaseMonthly('db-s-1vcpu-1gb'))->toBe(15.0);
    expect($estimator->cacheMonthly('db-s-2vcpu-4gb'))->toBe(60.0);
    expect($estimator->databaseMonthly('unknown-size'))->toBe(0.0);
});
test('for site sums function fee plus provisioned resources', function () {
    config(['subscription.standard.serverless_cents' => 200]);

    $site = Site::factory()->create(['meta' => ['serverless' => [
        'database' => ['size' => 'db-s-1vcpu-1gb', 'status' => 'online'],
        'cache' => ['size' => 'db-s-1vcpu-2gb', 'status' => 'online'],
    ]]]);

    $estimate = (new ServerlessCostEstimator)->forSite($site);

    // $2 function + $15 database + $30 Redis.
    expect($estimate['total'])->toBe(47.0);
    expect($estimate['lines'])->toHaveCount(3);
});
test('for site with no resources is just the function fee', function () {
    config(['subscription.standard.serverless_cents' => 200]);

    $site = Site::factory()->create(['meta' => ['serverless' => []]]);

    $estimate = (new ServerlessCostEstimator)->forSite($site);

    expect($estimate['total'])->toBe(2.0);
    expect($estimate['lines'])->toHaveCount(1);
});
