<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\RealtimeApp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealtimeApp>
 */
class RealtimeAppFactory extends Factory
{
    protected $model = RealtimeApp::class;

    public function definition(): array
    {
        $credentials = RealtimeApp::generateCredentials();

        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->words(2, true).' realtime',
            'app_key' => $credentials['app_key'],
            'app_secret' => $credentials['app_secret'],
            'status' => RealtimeApp::STATUS_ACTIVE,
            'backend' => 'dply_realtime',
            'tier' => 'starter',
            'host' => (string) config('realtime.host'),
            'max_connections' => (int) config('realtime.tiers.starter.max_connections', 5000),
        ];
    }

    public function tier(string $tier): static
    {
        return $this->state(fn (): array => [
            'tier' => $tier,
            'max_connections' => (int) config("realtime.tiers.{$tier}.max_connections", 5000),
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (): array => ['status' => RealtimeApp::STATUS_PROVISIONING]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => RealtimeApp::STATUS_PAUSED]);
    }
}
