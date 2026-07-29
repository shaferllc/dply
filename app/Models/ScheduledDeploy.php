<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Deploy\Console\RunDueScheduledDeploysCommand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-shot delayed deploy: a deploy queued to fire at {@see $run_at}. The
 * control-plane {@see RunDueScheduledDeploysCommand} tick
 * dispatches it once due, then marks it dispatched. Cancelable while pending.
 *
 * @property string $id
 * @property string $site_id
 * @property ?string $user_id
 * @property Carbon $run_at
 * @property string $status
 * @property ?Carbon $dispatched_at
 * @property ?Carbon $canceled_at
 * @property-read ?Site $site
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ScheduledDeploy extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'site_id',
        'user_id',
        'run_at',
        'status',
        'dispatched_at',
        'canceled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<ScheduledDeploy>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /** @param  Builder<ScheduledDeploy>  $query */
    public function scopeDue(Builder $query, Carbon $now): void
    {
        $query->where('status', self::STATUS_PENDING)->where('run_at', '<=', $now);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function markDispatched(?Carbon $at = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_DISPATCHED,
            'dispatched_at' => $at ?? now(),
        ])->save();
    }

    public function cancel(?Carbon $at = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_CANCELED,
            'canceled_at' => $at ?? now(),
        ])->save();
    }
}
