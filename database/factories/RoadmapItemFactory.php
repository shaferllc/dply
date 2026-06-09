<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RoadmapItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapItem>
 */
class RoadmapItemFactory extends Factory
{
    protected $model = RoadmapItem::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'summary' => fake()->optional()->sentence(),
            'description' => fake()->optional()->paragraph(),
            'status' => RoadmapItem::STATUS_PLANNED,
            'area' => fake()->randomElement(RoadmapItem::areaKeys()),
            'sort_order' => 0,
            'is_published' => true,
            'shipped_at' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }

    public function shipped(): static
    {
        return $this->state(fn () => [
            'status' => RoadmapItem::STATUS_SHIPPED,
            'shipped_at' => now()->toDateString(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => RoadmapItem::STATUS_IN_PROGRESS]);
    }
}
