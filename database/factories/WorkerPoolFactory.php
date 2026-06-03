<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\WorkerPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerPool>
 */
class WorkerPoolFactory extends Factory
{
    protected $model = WorkerPool::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->words(2, true).' pool',
            'source_server_id' => null,
            'primary_server_id' => null,
            'desired_count' => 1,
            'max_size' => 10,
            'status' => WorkerPool::STATUS_STEADY,
            'meta' => null,
        ];
    }
}
