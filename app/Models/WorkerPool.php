<?php

namespace App\Models;

use Database\Factories\WorkerPoolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * A worker pool groups a source worker server and its clones so background
 * throughput can be scaled declaratively. Exactly one member is the primary
 * (owns the scheduler / cron / migrations); the rest are queue-worker replicas.
 *
 * See doc/specs/worker-pools/02-specification.md.
 */
class WorkerPool extends Model
{
    /** @use HasFactory<WorkerPoolFactory> */
    use HasFactory, HasUlids;

    public const STATUS_STEADY = 'steady';

    public const STATUS_SCALING = 'scaling';

    public const STATUS_DEGRADED = 'degraded';

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_REPLICA = 'replica';

    /** Process manager that runs the pool's worker daemons (Horizon/scheduler). */
    public const PM_SYSTEMD = 'systemd';

    public const PM_SUPERVISOR = 'supervisor';

    /** Per-member sub-state stored under servers.meta['pool']['state']. */
    public const MEMBER_PROVISIONING = 'provisioning';

    public const MEMBER_REPLAYING = 'replaying';

    public const MEMBER_DEPLOYING = 'deploying';

    public const MEMBER_ACTIVE = 'active';

    public const MEMBER_DRAINING = 'draining';

    /**
     * Terminal failure: the member could not converge (e.g. its provider
     * instance vanished, or provisioning wedged past the stuck threshold). It is
     * left in place for the operator to inspect/remove — the reconciler stops
     * trying to advance it and surfaces the pool as degraded.
     */
    public const MEMBER_ERRORED = 'errored';

    protected $fillable = [
        'organization_id',
        'name',
        'source_server_id',
        'primary_server_id',
        'desired_count',
        'max_size',
        'status',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'desired_count' => 'integer',
            'max_size' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * Which process manager the pool's worker daemons run under. Stored in
     * meta['process_manager']; defaults to systemd (dply's canonical worker
     * runtime). The toggle on the Horizon tab flips this, and the next
     * "ensure workers" provisions the chosen backend and tears down the other.
     */
    public function processManager(): string
    {
        $pm = (string) ($this->meta['process_manager'] ?? self::PM_SYSTEMD);

        return in_array($pm, [self::PM_SYSTEMD, self::PM_SUPERVISOR], true) ? $pm : self::PM_SYSTEMD;
    }

    public function usesSupervisor(): bool
    {
        return $this->processManager() === self::PM_SUPERVISOR;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function sourceServer(): BelongsTo {
        return $this->belongsTo(Server::class, 'source_server_id');
    }

    /** @return BelongsTo<Server, $this> */
    public function primaryServer(): BelongsTo {
        return $this->belongsTo(Server::class, 'primary_server_id');
    }

    /** @return HasMany<Server> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class)->orderBy('created_at');
    }

    public function replicas(): HasMany
    {
        return $this->servers()->where('pool_role', self::ROLE_REPLICA);
    }

    /**
     * Members that count toward the desired size — everything except members
     * currently draining for removal.
     */
    public function activeMemberCount(): int
    {
        return $this->servers()
            ->get(['id', 'meta'])
            ->reject(fn (Server $s): bool => ($s->meta['pool']['state'] ?? null) === self::MEMBER_DRAINING)
            ->count();
    }
}
