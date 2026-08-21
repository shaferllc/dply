<?php

declare(strict_types=1);

namespace App\Modules\Cache\Models;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ServiceCredential;
use App\Models\Site;
use App\Modules\Cache\Services\PostgresCacheStore;
use App\Modules\Cache\Support\CacheUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A managed cache.
 *
 * Named `ManagedCache` rather than `Cache` on purpose: `Illuminate\Support\
 * Facades\Cache` is imported in dozens of files here, and a model that shadows
 * it would turn every one of those imports into a decision.
 *
 * Two tiers, one model (docs/adr/dply-cache.md, decision 3):
 *  - `shared` — the free Postgres-backed store, spoken to over a
 *    DynamoDB-compatible endpoint. Bounded by a byte quota, TTL-only.
 *  - `dedicated` — owns nothing itself; delegates to a {@see CloudDatabase}
 *    with `engine=redis`, reusing every backend and job in Modules/Database.
 *
 * @property string $id
 * @property ?string $cloud_database_id
 * @property ?Carbon $grandfathered_at
 * @property ?string $error_message
 * @property array<string, mixed>|null $meta
 * @property string $name
 * @property string $organization_id
 * @property ?int $quota_bytes
 * @property string $status
 * @property string $tier
 * @property-read ?Organization $organization
 * @property-read ?CloudDatabase $cloudDatabase
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ManagedCache extends Model
{
    use HasUlids;

    protected $table = 'managed_caches';

    public const TIER_SHARED = 'shared';

    public const TIER_DEDICATED = 'dedicated';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    protected $fillable = [
        'organization_id',
        'name',
        'tier',
        'status',
        'cloud_database_id',
        'quota_bytes',
        'grandfathered_at',
        'error_message',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'grandfathered_at' => 'datetime',
            'quota_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<CloudDatabase, $this> */
    public function cloudDatabase(): BelongsTo
    {
        return $this->belongsTo(CloudDatabase::class, 'cloud_database_id');
    }

    /** @return BelongsToMany<Site, $this, CacheSite, 'pivot'> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'cache_site', 'cache_id', 'site_id')
            ->using(CacheSite::class)
            ->withPivot('key_prefix')
            ->withTimestamps();
    }

    /**
     * Credentials granted on this cache.
     *
     * A query, not a relation — credentials are org-owned keys carrying a
     * grant map, so there is no `cache_id` column to hang a `hasMany` on.
     *
     * @return Builder<ServiceCredential>
     */
    public function credentials(): Builder
    {
        return ServiceCredential::query()->forResource(ServiceCredential::SERVICE_CACHE, $this->id);
    }

    public function isShared(): bool
    {
        return $this->tier === self::TIER_SHARED;
    }

    /**
     * Whether this cache is usable.
     *
     * For a dedicated cache the answer is the CLUSTER's, not this row's. The
     * cluster already has a provisioning job that maintains its own status, so
     * mirroring it into a second column would create two truths that drift
     * whenever a poll lands between them. Delegating means there is nothing to
     * keep in sync.
     */
    public function isActive(): bool
    {
        if ($this->isShared()) {
            return $this->status === self::STATUS_ACTIVE;
        }

        return $this->cloudDatabase?->isActive() ?? false;
    }

    /** The status to show, resolved the same way {@see isActive()} resolves. */
    public function effectiveStatus(): string
    {
        if ($this->isShared()) {
            return $this->status;
        }

        return match ($this->cloudDatabase?->status) {
            CloudDatabase::STATUS_ACTIVE => self::STATUS_ACTIVE,
            CloudDatabase::STATUS_FAILED => self::STATUS_FAILED,
            CloudDatabase::STATUS_DELETING => self::STATUS_DELETING,
            default => self::STATUS_PROVISIONING,
        };
    }

    /**
     * Whether the dply-hosted data plane should serve requests for this cache.
     *
     * Shared only, and deliberately narrower than {@see isActive()}: a
     * dedicated cache is a cluster the customer's app dials directly, so there
     * is nothing for the compatibility endpoint to serve and a request naming
     * one is a ResourceNotFound rather than a redirect.
     */
    public function isReachable(): bool
    {
        return $this->isShared() && $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The byte ceiling, falling through to the configured default so raising
     * the global dial lifts every cache that has no explicit one.
     */
    public function quotaBytes(): int
    {
        return $this->quota_bytes ?? (int) config('cache_service.shared.quota_bytes', 64 * 1024 * 1024);
    }

    /**
     * Current occupancy.
     *
     * Deliberately a lookup rather than a column: the counter lives beside the
     * items on the `dply_cache` connection, because that connection is meant to
     * be a separate database and a trigger cannot update across one. Callers on
     * a hot path should read it once and pass it down rather than calling this
     * per operation.
     */
    public function usage(): CacheUsage
    {
        return app(PostgresCacheStore::class)->usage($this->id);
    }

    /**
     * Whether dply bills for this cache.
     *
     * The shared tier is free for everyone (decision 7), so only a dedicated
     * cache can cost anything — and not if it was adopted by the M4 fold-in,
     * which grandfathers clusters that were provisioned when they were free.
     */
    public function isBillable(): bool
    {
        return ! $this->isShared()
            && $this->grandfathered_at === null
            && (bool) config('cache_service.billing.enabled', false);
    }
}
