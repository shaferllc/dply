<?php

namespace App\Models;

use Database\Factories\MarketplaceItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketplaceItem extends Model
{
    /** @use HasFactory<MarketplaceItemFactory> */
    use HasFactory, HasUlids;

    public const CATEGORY_SERVERS = 'servers';

    public const CATEGORY_SITES = 'sites';

    public const CATEGORY_WEBSERVER = 'webserver';

    public const CATEGORY_INTEGRATIONS = 'integrations';

    public const CATEGORY_GUIDES = 'guides';

    public const RECIPE_WEBSERVER_TEMPLATE = 'webserver_template';

    public const RECIPE_DEPLOY_COMMAND = 'deploy_command';

    public const RECIPE_SERVER_RECIPE = 'server_recipe';

    public const RECIPE_EXTERNAL_LINK = 'external_link';

    protected $fillable = [
        'slug',
        'name',
        'summary',
        'category',
        'recipe_type',
        'payload',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCategory(Builder $query, ?string $category): Builder
    {
        if ($category === null || $category === '' || $category === 'all') {
            return $query;
        }

        return $query->where('category', $category);
    }

    /**
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            'all' => __('All'),
            self::CATEGORY_SERVERS => __('Servers'),
            self::CATEGORY_SITES => __('Sites'),
            self::CATEGORY_WEBSERVER => __('Webserver'),
            self::CATEGORY_INTEGRATIONS => __('Integrations'),
            self::CATEGORY_GUIDES => __('Guides'),
        ];
    }
}
