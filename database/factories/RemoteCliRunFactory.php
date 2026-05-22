<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RemoteCliRun;
use App\Models\Site;
use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RiskLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemoteCliRun>
 */
class RemoteCliRunFactory extends Factory
{
    protected $model = RemoteCliRun::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'kind' => Kind::Wp,
            'command' => 'plugin list',
            'args' => ['--format=json'],
            'risk' => RiskLevel::Read,
            'mode' => RemoteCliRun::MODE_SYNC,
            'status' => RemoteCliRun::STATUS_COMPLETED,
            'exit_code' => 0,
            'stdout' => '[]',
            'stderr' => null,
            'queued_by_user_id' => null,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'cancelled_at' => null,
        ];
    }

    public function artisan(): self
    {
        return $this->state(fn () => [
            'kind' => Kind::Artisan,
            'command' => 'migrate:status',
            'args' => [],
            'stdout' => 'No migrations.',
        ]);
    }

    public function destructive(): self
    {
        return $this->state(fn () => [
            'risk' => RiskLevel::Destructive,
            'command' => 'db drop',
        ]);
    }

    public function async(): self
    {
        return $this->state(fn () => [
            'mode' => RemoteCliRun::MODE_ASYNC,
            'status' => RemoteCliRun::STATUS_QUEUED,
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'stdout' => null,
        ]);
    }
}
