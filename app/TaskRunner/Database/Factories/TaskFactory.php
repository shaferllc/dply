<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Database\Factories;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'action' => fake()->slug(),
            'script' => 'echo "ok"',
            'timeout' => 300,
            'user' => 'root',
            'status' => TaskStatus::Pending,
            'options' => [],
        ];
    }
}
