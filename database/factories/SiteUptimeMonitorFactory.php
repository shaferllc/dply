<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteUptimeMonitor>
 */
class SiteUptimeMonitorFactory extends Factory
{
    protected $model = SiteUptimeMonitor::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'label' => fake()->words(2, true).' check',
            'path' => null,
            'probe_region' => 'eu-amsterdam',
            'sort_order' => 0,
            'last_checked_at' => null,
            'last_ok' => null,
            'last_http_status' => null,
            'last_latency_ms' => null,
            'last_error' => null,
        ];
    }
}
