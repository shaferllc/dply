<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A worker pool groups a source worker server and its clones so background
 * throughput can be scaled declaratively. Exactly one member is the primary
 * (owns the scheduler / cron / migrations); the rest are queue-worker replicas.
 *
 * See doc/specs/worker-pools/02-specification.md.
 */
class WorkerPool extends Model
{
    /** @use HasFactory<\Database\Factories\WorkerPoolFactory> */
    use HasFactory, HasUlids;

    public const STATUS_STEADY = 'steady';

    public const STATUS_SCALING = 'scaling';

    public const STATUS_DEGRADED = 'degraded';

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_REPLICA = 'replica';

    /** Per-member sub-state stored under servers.meta['pool']['state']. */
    public const MEMBER_PROVISIONING = 'provisioning';

    public const MEMBER_REPLAYING = 'replaying';

    public const MEMBER_ACTIVE = 'active';

    public const MEMBER_DRAINING = 'draining';

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

    protected function casts(): array
    {
        return [
            'desired_count' => 'integer',
            'max_size' => 'integer',
            'meta' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sourceServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'source_server_id');
    }

    public function primaryServer(): BelongsTo
    {
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
