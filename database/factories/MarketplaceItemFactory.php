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
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
