<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RoadmapSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapSuggestion>
 */
class RoadmapSuggestionFactory extends Factory
{
    protected $model = RoadmapSuggestion::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'email' => fake()->safeEmail(),
            'name' => fake()->optional()->name(),
            'status' => RoadmapSuggestion::STATUS_NEW,
            'admin_notes' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function reviewed(): static
    {
        return $this->state(fn () => ['status' => RoadmapSuggestion::STATUS_REVIEWED]);
    }

    public function declined(): static
    {
        return $this->state(fn () => ['status' => RoadmapSuggestion::STATUS_DECLINED]);
    }
}
