<?php

declare(strict_types=1);

namespace App\Modules\Queue\Models;

use App\Models\Organization;
use App\Modules\Queue\Services\FleetAutoscaler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A managed worker fleet: dply-owned workers draining one queue name in one
 *                      namespace. The unit the customer creates, sizes, scales and is billed for.
 *                      Sizing is two numbers — memory per worker and the [min, max] worker range.
 *                      Everything else (when to add a worker, where it lands, how long its lease
 *                      runs) is dply's problem, which is the product.
 * @property string $class
 * @property ?Carbon $last_scaled_at
 * @property int $max_workers
 * @property int $memory_mib
 * @property array<string, mixed>|null $meta
 * @property int $min_workers
 * @property string $namespace_id
 * @property string $organization_id
 * @property string $queue
 * @property int $quiet_ticks
 * @property string $status
 * @property int $desired_workers
 * @property-read ?QueueNamespace $namespace
 * @property-read Organization $organization
 * @property-read Collection<int, ManagedQueueWorker> $workers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ManagedQueueFleet extends Model
{
    use HasUlids;

    /** Sleeps at zero, wakes on push. The default, and the cheap one. */
    public const CLASS_FLEX = 'flex';

    /** Always at least one worker, no job-runtime ceiling. */
    public const CLASS_PRO = 'pro';

    public const STATUS_ACTIVE = 'active';

    /**
     * Operator stopped processing. Workers wind down to zero and nothing is
     * billed, but pushes keep landing — the backlog is held, not dropped,
     * and drains when the fleet resumes.
     */
    public const STATUS_PAUSED = 'paused';

    protected $table = 'dply_queue_fleets';

    protected $fillable = [
        'namespace_id',
        'organization_id',
        'queue',
        'class',
        'status',
        'memory_mib',
        'min_workers',
        'max_workers',
        'desired_workers',
        'quiet_ticks',
        'last_scaled_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'memory_mib' => 'integer',
            'min_workers' => 'integer',
            'max_workers' => 'integer',
            'desired_workers' => 'integer',
            'quiet_ticks' => 'integer',
            'last_scaled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<QueueNamespace, $this> */
    public function namespace(): BelongsTo
    {
        return $this->belongsTo(QueueNamespace::class, 'namespace_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ManagedQueueWorker, $this> */
    public function workers(): HasMany
    {
        return $this->hasMany(ManagedQueueWorker::class, 'fleet_id');
    }

    /**
     * The floor this fleet may scale to.
     *
     * A pro fleet is defined by never sleeping, so its floor is at least one
     * whatever the operator typed — the alternative is a "pro" fleet that
     * cold-starts, which is the one thing the class promises not to do.
     */
    public function scalingFloor(): int
    {
        if ($this->status === self::STATUS_PAUSED) {
            return 0;
        }

        return $this->class === self::CLASS_PRO
            ? max(1, $this->min_workers)
            : max(0, $this->min_workers);
    }

    public function scalingCeiling(): int
    {
        return max($this->scalingFloor(), $this->max_workers);
    }

    /**
     * Whether a push should wake this fleet rather than wait for the tick.
     *
     * Only fleets that can actually be at zero need waking; a fleet with a
     * floor already has a worker holding the queue open. {@see FleetAutoscaler}
     */
    /**
     * Seconds a worker gets to finish its current job after being asked to stop.
     *
     * Pro fleets get an hour because they run the long jobs people bought them
     * for; flex gets 90s, enough for a normal job and short enough that a
     * scale-down is not held open by one straggler.
     */
    public function graceSeconds(): int
    {
        return $this->class === self::CLASS_PRO ? 3600 : 90;
    }

    public function wakesOnPush(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->scalingFloor() === 0;
    }
}
