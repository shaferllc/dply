<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted baseline for an org's bundled-products entitlement (free tracely +
 * Lookout). The synchronizer diffs this against the live
 * {@see Organization::qualifiesForBundledProducts()} predicate to emit
 * `bundle.*` transitions. See docs/adr/bundled-products-sso.md.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $status
 * @property ?\Illuminate\Support\Carbon $provisioned_at
 * @property ?\Illuminate\Support\Carbon $suspended_at
 * @property ?\Illuminate\Support\Carbon $purged_at
 */
class OrganizationBundleEntitlement extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'organization_id',
        'status',
        'provisioned_at',
        'suspended_at',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'provisioned_at' => 'datetime',
            'suspended_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
