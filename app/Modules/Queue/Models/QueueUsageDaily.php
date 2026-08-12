<?php

declare(strict_types=1);

namespace App\Modules\Queue\Models;

use App\Models\Organization;
use App\Modules\Queue\Services\QueueUsageMeter;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      Per-org daily rollup of dply Queue jobs pushed — the billable number
 *                      (docs/adr/dply-queue.md, decision 9), flushed out of the live counters by
 *                      {@see QueueUsageMeter}.
 *                      Jobs pushed, not API requests: billing per request would charge the
 *                      customer for dply's polling design and reward us for never improving it.
 * @property string $organization_id
 * @property Carbon $day
 * @property int $jobs_pushed
 * @property string $source
 * @property ?array<string, mixed> $meta
 * @property-read ?Organization $organization
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class QueueUsageDaily extends Model
{
    use HasUlids;

    /** Flushed from the push counters — the billable source of truth. */
    public const SOURCE_COUNTER = 'counter';

    /** Hand-entered adjustments (credits, backfills, disputes). */
    public const SOURCE_MANUAL = 'manual';

    /** Explicit — inference would give `queue_usage_dailies`. */
    protected $table = 'dply_queue_usage_daily';

    protected $fillable = [
        'organization_id',
        'day',
        'jobs_pushed',
        'source',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'jobs_pushed' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Jobs pushed by an org across a day range, inclusive — what the cost
     * calculator and the UI both bill and display from.
     */
    public static function totalFor(string $organizationId, Carbon $from, Carbon $to): int
    {
        return (int) self::query()
            ->where('organization_id', $organizationId)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->sum('jobs_pushed');
    }
}
