<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * A queued quick-download request. The build job stages a freshly-built artifact
 * into the operator-managed download bucket (see config/backup_staging.php), the
 * user is notified in-app + by email, and the signed proxy route streams it on
 * demand — re-downloadable, never deleted on download. The artifact is retained
 * until {@see expires_at} (config/quick_download.php retention_minutes), when the
 * sweeper prunes it. This is a short-lived download tier, not a durable backup.
 */
class QuickDownload extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_BUILDING = 'building';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_EXPIRED = 'expired';

    /** A catalogued ServerDatabase dump. */
    public const KIND_DATABASE = 'database';

    /** A database that exists on the box but isn't a ServerDatabase row. */
    public const KIND_ADHOC_DATABASE = 'adhoc_database';

    /** One of the per-site artifacts (files/env/vhost/logs/home/bundle). */
    public const KIND_SITE = 'site';

    protected $table = 'quick_downloads';

    protected $fillable = [
        'organization_id',
        'server_id',
        'site_id',
        'server_database_id',
        'kind',
        'artifact',
        'meta',
        'requested_by_user_id',
        'status',
        'bucket',
        'object_key',
        'bytes',
        'filename',
        'mime',
        'error_message',
        'expires_at',
        'consumed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'bytes' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<ServerDatabase, $this> */
    public function serverDatabase(): BelongsTo {
        return $this->belongsTo(ServerDatabase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * "Large" artifacts (at/above the notify threshold) are delivered by
     * notification + a link the user clicks; smaller ones auto-download from the
     * page poll. Size is only known once the build lands, so this reads `bytes`.
     */
    public function isLarge(): bool
    {
        return (int) ($this->bytes ?? 0) >= (int) config('quick_download.notify_threshold_bytes', 5_242_880);
    }

    /** Ready, not yet consumed, and still inside its retention window. */
    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /** Still working toward a ready artifact (so a repeat click can reuse it). */
    public function isActive(): bool
    {
        if (in_array($this->status, [self::STATUS_PENDING, self::STATUS_BUILDING], true)) {
            return true;
        }

        return $this->isDownloadable();
    }

    /** A staged bucket object exists and should be deleted on cleanup. */
    public function hasStagedObject(): bool
    {
        return filled($this->bucket) && filled($this->object_key);
    }

    /**
     * Sweepable rows: ready-but-expired, or terminal rows (consumed/failed/expired)
     * older than the TTL window — so staged objects and stale rows don't accumulate.
     */
    public function scopeSweepable(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('status', self::STATUS_READY)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now());
        })->orWhere(function (Builder $q): void {
            $q->whereIn('status', [self::STATUS_CONSUMED, self::STATUS_FAILED, self::STATUS_EXPIRED])
                ->where('updated_at', '<', now()->subMinutes((int) config('backup_staging.ttl_minutes', 240)));
        });
    }
}
