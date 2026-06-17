<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A warm server pool member — a pre-provisioned spare kept ready in a
 * provider×region×size×tier bucket so a create can claim + personalize it
 * instead of cold-provisioning. The actual VM is a {@see Server} (owned by the
 * system pool org until claimed); this row tracks bucket + lifecycle state.
 *
 * @property string $id
 * @property string $provider
 * @property string $region
 * @property string $size
 * @property string $tier
 * @property string|null $stack_signature
 * @property string|null $server_id
 * @property string $status
 * @property Carbon|null $health_checked_at
 * @property string|null $claimed_org_id
 * @property Carbon|null $claimed_at
 * @property array|null $meta
 */
class ServerPoolMember extends Model
{
    use HasUlids;

    public const TIER_BASELINE = 'baseline';

    public const TIER_STACK = 'stack';

    public const STATUS_WARMING = 'warming';

    public const STATUS_READY = 'ready';

    public const STATUS_CLAIMING = 'claiming';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_RETIRING = 'retiring';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'health_checked_at' => 'datetime',
        'claimed_at' => 'datetime',
        'meta' => 'array',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class, 'claimed_org_id');
    }

    /** Members that count toward a bucket's "available" capacity. */
    public function scopeAvailable($query)
    {
        return $query->whereIn('status', [self::STATUS_WARMING, self::STATUS_READY]);
    }

    public function scopeForBucket($query, string $provider, string $region, string $size, string $tier)
    {
        return $query->where('provider', $provider)
            ->where('region', $region)
            ->where('size', $size)
            ->where('tier', $tier);
    }

    /**
     * Canonical signature of the stack-relevant fields, so a hot-stack member is
     * only matched to a create requesting the SAME stack. Stable regardless of
     * key order / extra meta keys.
     *
     * @param  array<string, mixed>  $stack
     */
    public static function signatureFor(array $stack): string
    {
        $keys = ['server_role', 'webserver', 'php_version', 'database', 'cache_service'];
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = $k.'='.(string) ($stack[$k] ?? '');
        }

        return implode('|', $parts);
    }
}
