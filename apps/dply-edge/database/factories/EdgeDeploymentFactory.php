<?php

namespace Database\Factories;

use App\Models\EdgeDeployment;
use App\Models\EdgeProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EdgeDeployment>
 */
class EdgeDeploymentFactory extends Factory
{
    protected $model = EdgeDeployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'edge_project_id' => EdgeProject::factory(),
            'application_name' => fake()->slug(),
            'framework' => 'next',
            'git_ref' => 'main',
            'status' => EdgeDeployment::STATUS_QUEUED,
            'trigger' => EdgeDeployment::TRIGGER_API,
            'idempotency_key' => null,
        ];
    }
}
