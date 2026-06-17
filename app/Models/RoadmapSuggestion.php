<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RoadmapSuggestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 */

class RoadmapSuggestion extends Model
{
    /** @use HasFactory<RoadmapSuggestionFactory> */
    use HasFactory, HasUlids;

    public const STATUS_NEW = 'new';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DECLINED = 'declined';

    protected $fillable = [
        'title',
        'description',
        'email',
        'name',
        'status',
        'admin_notes',
        'promoted_roadmap_item_id',
        'ip_address',
    ];

    /**
     * @return list<string>
     */
    public static function statusKeys(): array
    {
        return array_keys(config('roadmap.suggestion_statuses', []));
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '' || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if ($search === null || trim($search) === '') {
            return $query;
        }

        $term = '%'.Str::lower(trim($search)).'%';

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->whereRaw('LOWER(title) LIKE ?', [$term])
                ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                ->orWhereRaw('LOWER(name) LIKE ?', [$term]);
        });
    }

    /**
     * @return BelongsTo<RoadmapItem, $this>
     */
    public function promotedRoadmapItem(): BelongsTo
    {
        return $this->belongsTo(RoadmapItem::class, 'promoted_roadmap_item_id');
    }

    public function statusLabel(): string
    {
        return (string) (config('roadmap.suggestion_statuses.'.$this->status) ?? $this->status);
    }
}
