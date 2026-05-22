<?php

namespace Database\Factories;

use App\Models\CloudWorker;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CloudWorker>
 */
class CloudWorkerFactory extends Factory
{
    protected $model = CloudWorker::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => CloudWorker::TYPE_WORKER,
            'name' => 'worker-'.Str::lower(Str::random(6)),
            'command' => CloudWorker::DEFAULT_WORKER_COMMAND,
            'size' => 'small',
            'instance_count' => 1,
            'status' => CloudWorker::STATUS_PROVISIONING,
            'meta' => null,
        ];
    }

    public function worker(): static
    {
        return $this->state(fn (): array => [
            'type' => CloudWorker::TYPE_WORKER,
            'name' => 'worker-'.Str::lower(Str::random(6)),
            'command' => CloudWorker::DEFAULT_WORKER_COMMAND,
        ]);
    }

    public function scheduler(): static
    {
        return $this->state(fn (): array => [
            'type' => CloudWorker::TYPE_SCHEDULER,
            'name' => 'scheduler',
            'command' => CloudWorker::SCHEDULER_COMMAND,
            'instance_count' => 1,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => CloudWorker::STATUS_ACTIVE,
        ]);
    }
}
