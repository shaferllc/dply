<?php

declare(strict_types=1);

namespace App\Modules\Queue\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      One managed worker — a single container's whole life, and the row the
 *                      customer is billed from. It outlives the container deliberately: seconds
 *                      are invoiced from started_at/stopped_at, so teardown settles a row rather
 *                      than deleting one.
 * @property ?Carbon $billed_at
 * @property ?int $billed_seconds
 * @property string $fleet_id
 * @property ?string $host_server_id
 * @property ?Carbon $last_seen_at
 * @property int $memory_mib
 * @property array<string, mixed>|null $meta
 * @property ?Carbon $ready_at
 * @property string $runtime
 * @property ?string $runtime_ref
 * @property ?Carbon $started_at
 * @property string $state
 * @property ?string $stop_reason
 * @property ?Carbon $stopped_at
 * @property-read ManagedQueueFleet $fleet
 * @property-read ?Server $hostServer
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ManagedQueueWorker extends Model
{
    use HasUlids;

    /** Container asked for, not yet answering. Billed — we are paying for it. */
    public const STATE_STARTING = 'starting';

    public const STATE_RUNNING = 'running';

    /** Told to stop; finishing its current job inside the grace period. */
    public const STATE_DRAINING = 'draining';

    public const STATE_STOPPED = 'stopped';

    /** Never came up, or died in a way the runtime could not explain. */
    public const STATE_ERRORED = 'errored';

    /** Occupying capacity and accruing cost — the set the autoscaler counts. */
    public const LIVE_STATES = [self::STATE_STARTING, self::STATE_RUNNING, self::STATE_DRAINING];

    protected $table = 'dply_queue_workers';

    protected $fillable = [
        'fleet_id',
        'runtime',
        'runtime_ref',
        'host_server_id',
        'state',
        'stop_reason',
        'memory_mib',
        'started_at',
        'ready_at',
        'stopped_at',
        'last_seen_at',
        'billed_seconds',
        'billed_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'memory_mib' => 'integer',
            'billed_seconds' => 'integer',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'stopped_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'billed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<ManagedQueueFleet, $this> */
    public function fleet(): BelongsTo
    {
        return $this->belongsTo(ManagedQueueFleet::class, 'fleet_id');
    }

    /** @return BelongsTo<Server, $this> */
    public function hostServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'host_server_id');
    }

    /**
     * Workers that exist as far as cost and capacity are concerned.
     *
     * `draining` counts: the container is still up, still holding memory on a
     * host, and still billed until it actually exits.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('state', self::LIVE_STATES);
    }

    /**
     * Seconds this worker has been alive — settled if it has stopped, running
     * total if it has not.
     */
    public function billableSeconds(): int
    {
        if ($this->billed_seconds !== null) {
            return $this->billed_seconds;
        }

        if (! $this->started_at instanceof Carbon) {
            return 0;
        }

        return (int) $this->started_at->diffInSeconds($this->stopped_at ?? now(), absolute: true);
    }
}
