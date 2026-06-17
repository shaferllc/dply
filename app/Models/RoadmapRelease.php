<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Roadmap\RoadmapReleaseTrain;
use Database\Factories\RoadmapReleaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
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

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('slug')->orderByDesc('sort_order');
    }

    /**
     * @return HasMany<RoadmapItem, $this>
     */
    public function targetItems(): HasMany
    {
        return $this->hasMany(RoadmapItem::class, 'target_release_id');
    }

    /**
     * @return HasMany<RoadmapItem, $this>
     */
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
