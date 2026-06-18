<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Roadmap\Support\RoadmapReleaseTrain;
use Database\Factories\RoadmapReleaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property bool $is_published
 * @property ?Carbon $published_at
 * @property string $slug
 * @property int $sort_order
 * @property string $summary
 * @property string $title
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Collection<int, RoadmapItem> $targetItems
 * @property-read Collection<int, RoadmapItem> $shippedItems
 */
class RoadmapRelease extends Model
{
    /** @use HasFactory<RoadmapReleaseFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'slug',
        'title',
        'summary',
        'published_at',
        'is_published',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('slug')->orderByDesc('sort_order');
    }

    /**
     * @return HasMany<RoadmapItem, $this>
     */
    /** @return HasMany<RoadmapItem, $this> */
    public function targetItems(): HasMany
    {
        return $this->hasMany(RoadmapItem::class, 'target_release_id');
    }

    /**
     * @return HasMany<RoadmapItem, $this>
     */
    /** @return HasMany<RoadmapItem, $this> */
    public function shippedItems(): HasMany
    {
        return $this->hasMany(RoadmapItem::class, 'shipped_release_id');
    }

    public function monthLabel(): string
    {
        return RoadmapReleaseTrain::monthLabel($this->slug);
    }

    public function trainLabel(): string
    {
        return RoadmapReleaseTrain::trainLabel($this->slug);
    }

    public function displayTitle(): string
    {
        if (filled($this->title)) {
            return (string) $this->title;
        }

        return $this->monthLabel();
    }
}
