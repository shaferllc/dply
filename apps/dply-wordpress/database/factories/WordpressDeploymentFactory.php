<?php

namespace Database\Factories;

use App\Models\WordpressDeployment;
use App\Models\WordpressProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WordpressDeployment>
 */
class WordpressDeploymentFactory extends Factory
{
    protected $model = WordpressDeployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wordpress_project_id' => WordpressProject::factory()->hosted(),
            'application_name' => fake()->slug(),
            'php_version' => '8.3',
            'git_ref' => 'main',
            'status' => WordpressDeployment::STATUS_QUEUED,
            'trigger' => WordpressDeployment::TRIGGER_API,
            'idempotency_key' => null,
        ];
    }
}
