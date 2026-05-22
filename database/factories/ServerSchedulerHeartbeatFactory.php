<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerSchedulerHeartbeat>
 */
class ServerSchedulerHeartbeatFactory extends Factory
{
    protected $model = ServerSchedulerHeartbeat::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'site_id' => Site::factory(),
            'scheduler_kind' => ServerSchedulerHeartbeat::KIND_LARAVEL,
            'cron_expression' => '* * * * *',
            'last_tick_at' => now(),
            'last_exit_code' => 0,
            'last_duration_ms' => 800,
            'last_memory_peak_kb' => 12_000,
            'consecutive_misses' => 0,
            'first_seen_at' => now(),
            'circuit_open' => false,
            'output_capture_enabled' => true,
        ];
    }

    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_tick_at' => now()->subMinutes(10),
            'consecutive_misses' => 9,
        ]);
    }

    public function waitingForFirstTick(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_tick_at' => null,
            'consecutive_misses' => 0,
            'first_seen_at' => now()->subSeconds(30),
        ]);
    }
}
