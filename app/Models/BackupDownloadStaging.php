<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An ephemeral copy of a backup staged for one-time download. Hetzner-mode rows
 * hold a temporary object in the global staging bucket (deleted by the sweeper
 * after {@see expires_at}); direct-mode rows just point the browser at an
 * already-presignable durable S3 object (nothing to copy or delete).
 */
class BackupDownloadStaging extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** A temporary copy lives in the staging bucket and must be swept. */
    public const MODE_HETZNER = 'hetzner';

    /** The durable artifact is already a presignable S3 object; no staged copy. */
    public const MODE_DIRECT = 'direct';

    protected $table = 'backup_download_stagings';

    protected $fillable = [
        'backupable_type',
        'backupable_id',
        'requested_by_user_id',
        'status',
        'mode',
        'bucket',
        'object_key',
        'error_message',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function backupable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }
}
