<?php

namespace Database\Factories;

use App\Models\MarketplaceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceItem>
 */
class MarketplaceItemFactory extends Factory
{
    protected $model = MarketplaceItem::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'summary' => fake()->sentence(),
            'category' => MarketplaceItem::CATEGORY_GUIDES,
            'recipe_type' => MarketplaceItem::RECIPE_EXTERNAL_LINK,
            'payload' => ['url' => '/docs', 'open_new_tab' => false],
            'runtimes' => null,
            'frameworks' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * @param  list<string>  $runtimes
     */
    public function forRuntimes(array $runtimes): static
    {
        return $this->state(fn () => ['runtimes' => $runtimes]);
    }

    /**
     * @param  list<string>  $frameworks
     */
    public function forFrameworks(array $frameworks): static
    {
        return $this->state(fn () => ['frameworks' => $frameworks]);
    }
}
