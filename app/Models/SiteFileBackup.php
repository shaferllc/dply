<?php

namespace App\Models;

use Database\Factories\SiteFileBackupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $site_id
 * @property ?string $user_id
 * @property string $status
 * @property string $storage_kind
 * @property ?string $disk_path
 * @property ?string $remote_path
 * @property ?int $bytes
 * @property ?string $error_message
 * @property-read Site $site
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SiteFileBackup extends Model
{
    /** @use HasFactory<SiteFileBackupFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Durable archive lives on the site's own server (reachable over SSH). */
    public const STORAGE_KIND_REMOTE_SERVER = 'remote_server';

    /** Legacy: archive streamed to the control-plane local disk (same-box only). */
    public const STORAGE_KIND_CONTROL_PLANE = 'control_plane';

    protected $table = 'site_file_backups';

    protected $fillable = [
        'site_id',
        'user_id',
        'status',
        'storage_kind',
        'disk_path',
        'remote_path',
        'bytes',
        'error_message',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Effective storage kind. Rows written before the remote-server change have
     * no storage_kind but a disk_path — they're control-plane (local disk).
     */
    public function effectiveStorageKind(): string
    {
        if (filled($this->storage_kind)) {
            return (string) $this->storage_kind;
        }

        return filled($this->remote_path)
            ? self::STORAGE_KIND_REMOTE_SERVER
            : self::STORAGE_KIND_CONTROL_PLANE;
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && (filled($this->remote_path) || filled($this->disk_path));
    }
}
