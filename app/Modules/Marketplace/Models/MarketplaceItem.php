<?php

namespace App\Modules\Marketplace\Models;

use Database\Factories\MarketplaceItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $category
 * @property array<string, mixed> $frameworks
 * @property bool $is_active
 * @property string $name
 * @property array<string, mixed> $payload
 * @property string $recipe_type
 * @property array<string, mixed> $runtimes
 * @property string $slug
 * @property string $sort_order
 * @property string $summary
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MarketplaceItem extends Model
{
    /** @use HasFactory<MarketplaceItemFactory> */
    use HasFactory, HasUlids;

    public const CATEGORY_SERVERS = 'servers';

    public const CATEGORY_SITES = 'sites';

    public const CATEGORY_WEBSERVER = 'webserver';

    public const CATEGORY_INTEGRATIONS = 'integrations';

    public const CATEGORY_GUIDES = 'guides';

    public const CATEGORY_RUNBOOKS = 'runbooks';

    public const RECIPE_WEBSERVER_TEMPLATE = 'webserver_template';

    public const RECIPE_DEPLOY_COMMAND = 'deploy_command';

    public const RECIPE_SERVER_RECIPE = 'server_recipe';

    public const RECIPE_WORKSPACE_RUNBOOK = 'workspace_runbook';

    public const RECIPE_EXTERNAL_LINK = 'external_link';

    protected $fillable = [
        'slug',
        'name',
        'summary',
        'category',
        'recipe_type',
        'payload',
        'runtimes',
        'frameworks',
        'sort_order',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'runtimes' => 'array',
            'frameworks' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCategory(Builder $query, ?string $category): Builder
    {
        if ($category === null || $category === '' || $category === 'all') {
            return $query;
        }

        return $query->where('category', $category);
    }

    /**
     * Filters to items that apply to the given runtime, plus all "universal"
     * items (where the runtimes tag is null or an empty list).
     *
     * Pass null to return everything regardless of runtime tags — used by the
     * standalone marketplace page where there's no site/server context.
     */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForRuntime(Builder $query, ?string $runtime): Builder
    {
        if ($runtime === null || $runtime === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($runtime) {
            $q->whereNull('runtimes')
                ->orWhereJsonLength('runtimes', 0)
                ->orWhereJsonContains('runtimes', $runtime);
        });
    }

    /**
     * Filters to items that apply to the given framework, plus all items that
     * don't declare a framework tag (which are framework-agnostic).
     */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForFramework(Builder $query, ?string $framework): Builder
    {
        if ($framework === null || $framework === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($framework) {
            $q->whereNull('frameworks')
                ->orWhereJsonLength('frameworks', 0)
                ->orWhereJsonContains('frameworks', $framework);
        });
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
            self::CATEGORY_RUNBOOKS => __('Runbooks'),
        ];
    }
}
