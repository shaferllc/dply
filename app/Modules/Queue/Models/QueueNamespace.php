<?php

declare(strict_types=1);

namespace App\Modules\Queue\Models;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property string $id
 *                      A dply Queue namespace — one managed queue endpoint, and the tenancy unit
 *                      of the product. The row's ULID is the data-plane identifier: job rows key
 *                      off it directly, so nothing translates between a public name and an
 *                      internal id.
 *                      Not scoped to a Site. Any Laravel app can hold a namespace whether or not
 *                      dply deploys it; `site_id` is set only when dply does, so a push can wake
 *                      that site's queue pump without polling.
 * @property int $credential_epoch
 * @property ?string $error_message
 * @property ?int $max_queue_depth
 * @property array<string, mixed> $meta
 * @property string $name
 * @property ?string $organization_id
 * @property ?string $site_id
 * @property string $status
 * @property string $tier
 * @property-read ?Organization $organization
 * @property-read ?Site $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, QueueCredential> $credentials
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class QueueNamespace extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    /** Suspended by an operator or by a plan downgrade — pushes are rejected. */
    public const STATUS_PAUSED = 'paused';

    public const STATUS_FAILED = 'failed';

    /**
     * Explicit: inference would give `queue_namespaces`, which reads as though
     * it belonged to Laravel's own queue rather than to the product.
     */
    protected $table = 'dply_queue_namespaces';

    protected $fillable = [
        'organization_id',
        'site_id',
        'name',
        'status',
        'tier',
        'credential_epoch',
        'max_queue_depth',
        'error_message',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credential_epoch' => 'integer',
            'max_queue_depth' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<QueueCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(QueueCredential::class, 'namespace_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Whether this namespace may currently accept pushes. */
    public function acceptsPushes(): bool
    {
        return $this->isActive();
    }

    /**
     * Invalidate every cached credential for this namespace at once.
     *
     * Cached credential tuples carry the epoch they were minted under, so
     * bumping this forces a re-read on the next request without having to
     * enumerate credentials or know their cache keys.
     */
    public function bumpCredentialEpoch(): void
    {
        $this->forceFill(['credential_epoch' => $this->credential_epoch + 1])->save();
    }

    /**
     * Credentials that could authenticate a request right now — not revoked,
     * not expired. Two may be live at once during a rotation.
     *
     * @return Collection<int, QueueCredential>
     */
    public function liveCredentials()
    {
        return $this->credentials()
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get();
    }
}
