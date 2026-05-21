<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Serverless;

use App\Models\Site;
use App\Services\Serverless\ServerlessCostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerlessCostEstimatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_function_fee_comes_from_subscription_config(): void
    {
        config(['subscription.standard.serverless_cents' => 200]);

        $this->assertSame(2.0, (new ServerlessCostEstimator)->functionFee());
    }

    public function test_cluster_costs_come_from_the_pricing_config(): void
    {
        $estimator = new ServerlessCostEstimator;

        $this->assertSame(15.0, $estimator->databaseMonthly('db-s-1vcpu-1gb'));
        $this->assertSame(60.0, $estimator->cacheMonthly('db-s-2vcpu-4gb'));
        $this->assertSame(0.0, $estimator->databaseMonthly('unknown-size'));
    }

    public function test_for_site_sums_function_fee_plus_provisioned_resources(): void
    {
        config(['subscription.standard.serverless_cents' => 200]);

        $site = Site::factory()->create(['meta' => ['serverless' => [
            'database' => ['size' => 'db-s-1vcpu-1gb', 'status' => 'online'],
            'cache' => ['size' => 'db-s-1vcpu-2gb', 'status' => 'online'],
        ]]]);

        $estimate = (new ServerlessCostEstimator)->forSite($site);

        // $2 function + $15 database + $30 Redis.
        $this->assertSame(47.0, $estimate['total']);
        $this->assertCount(3, $estimate['lines']);
    }

    public function test_for_site_with_no_resources_is_just_the_function_fee(): void
    {
        config(['subscription.standard.serverless_cents' => 200]);

        $site = Site::factory()->create(['meta' => ['serverless' => []]]);

        $estimate = (new ServerlessCostEstimator)->forSite($site);

        $this->assertSame(2.0, $estimate['total']);
        $this->assertCount(1, $estimate['lines']);
    }
}
