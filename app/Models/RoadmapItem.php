<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Roadmap\RoadmapQuarter;
use Database\Factories\RoadmapItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 */

class RoadmapItem extends Model
{
    /** @use HasFactory<RoadmapItemFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SHIPPED = 'shipped';

    protected $fillable = [
        'title',
        'summary',
        'description',
        'status',
        'area',
        'target_quarter',
        'target_release_id',
        'shipped_release_id',
        'sort_order',
        'is_published',
        'shipped_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'shipped_at' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function statusKeys(): array
    {
        return array_keys(config('roadmap.statuses', []));
    }

    /**
     * @return list<string>
     */
    public static function areaKeys(): array
    {
        return array_keys(config('roadmap.areas', []));
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeArea(Builder $query, ?string $area): Builder
    {
        if ($area === null || $area === '' || $area === 'all') {
            return $query;
        }

        return $query->where('area', $area);
    }

    public function scopeReleaseFilter(Builder $query, ?string $releaseId): Builder
    {
        if ($releaseId === null || $releaseId === '' || $releaseId === 'all') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($releaseId): void {
            $inner->where('target_release_id', $releaseId)
                ->orWhere('shipped_release_id', $releaseId);
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title');
    }

    public function scopeRecentlyShipped(Builder $query): Builder
    {
        return $query->published()
            ->status(self::STATUS_SHIPPED)
            ->orderByDesc('shipped_at')
            ->orderByDesc('updated_at');
    }

    /**
     * @return HasMany<RoadmapSuggestion, $this>
     */
    public function sourceSuggestions(): HasMany
    {
        return $this->hasMany(RoadmapSuggestion::class, 'promoted_roadmap_item_id');
    }

    /**
     * @return BelongsTo<RoadmapRelease, $this>
     */
    public function targetRelease(): BelongsTo
    {
        return $this->belongsTo(RoadmapRelease::class, 'target_release_id');
    }

    /**
     * @return BelongsTo<RoadmapRelease, $this>
     */
    public function shippedRelease(): BelongsTo
    {
        return $this->belongsTo(RoadmapRelease::class, 'shipped_release_id');
    }

    public function statusLabel(): string
    {
        return (string) (config('roadmap.statuses.'.$this->status) ?? $this->status);
    }

    public function areaLabel(): ?string
    {
        if ($this->area === null || $this->area === '') {
            return null;
        }

        return (string) (config('roadmap.areas.'.$this->area) ?? $this->area);
    }

    public function targetQuarterLabel(): ?string
    {
        if ($this->target_quarter === null || $this->target_quarter === '') {
            return null;
        }

        if (! RoadmapQuarter::isValidKey($this->target_quarter)) {
            return $this->target_quarter;
        }

        return RoadmapQuarter::labelForKey($this->target_quarter);
    }
}
