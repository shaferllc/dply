<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RoadmapRelease;
use App\Modules\Roadmap\Support\RoadmapReleaseTrain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapRelease>
 */
class RoadmapReleaseFactory extends Factory
{
    protected $model = RoadmapRelease::class;

    public function definition(): array
    {
        $slug = RoadmapReleaseTrain::slugFromDate(now()->startOfMonth()->toImmutable());

        return [
            'slug' => $slug,
            'title' => null,
            'summary' => fake()->optional()->sentence(),
            'published_at' => now()->toDateString(),
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function forSlug(string $slug): static
    {
        return $this->state(fn () => ['slug' => $slug]);
    }
}
