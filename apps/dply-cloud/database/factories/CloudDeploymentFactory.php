<?php

namespace Database\Factories;

use App\Models\CloudDeployment;
use App\Models\CloudProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudDeployment>
 */
class CloudDeploymentFactory extends Factory
{
    protected $model = CloudDeployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cloud_project_id' => CloudProject::factory(),
            'application_name' => fake()->slug(),
            'stack' => 'php',
            'git_ref' => 'main',
            'status' => CloudDeployment::STATUS_QUEUED,
            'trigger' => CloudDeployment::TRIGGER_API,
            'idempotency_key' => null,
        ];
    }
}
