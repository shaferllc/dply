<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RoadmapItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'sort_order',
        'is_published',
        'shipped_at',
    ];

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

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('title');
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
}
