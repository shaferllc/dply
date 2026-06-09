<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One run of the post-deploy AI roadmap updater — both an audit record and the
 * cursor the next run reads from. {@see latestCompletedToCommit()} returns the
 * commit the previous successful run stopped at, so each deploy only reasons
 * about commits it hasn't processed yet.
 */
class RoadmapAiRun extends Model
{
    use HasUlids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'status',
        'from_commit',
        'to_commit',
        'commits_considered',
        'items_shipped',
        'items_created',
        'suggestions_triaged',
        'summaries_updated',
        'prompt_tokens',
        'completion_tokens',
        'latency_ms',
        'note',
        'plan',
    ];

    protected function casts(): array
    {
        return [
            'commits_considered' => 'integer',
            'items_shipped' => 'integer',
            'items_created' => 'integer',
            'suggestions_triaged' => 'integer',
            'summaries_updated' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'plan' => 'array',
        ];
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * The commit the last successful run stopped at — the lower bound for the
     * next run's git diff. Null when no run has completed yet (first deploy).
     */
    public static function latestCompletedToCommit(): ?string
    {
        $sha = self::query()->completed()
            ->whereNotNull('to_commit')
            ->latest('created_at')
            ->value('to_commit');

        return is_string($sha) && $sha !== '' ? $sha : null;
    }
}
