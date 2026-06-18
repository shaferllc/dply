<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Snapshots\Services\SnapshotService;
use Database\Factories\SnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Database snapshot of a Site's data, taken either manually or as the
 * automatic safety net before a destructive operation.
 * Stored either to local server disk (transient, TTL via expires_at) or
 * to a BYO S3-compatible bucket configured at the org level (durable).
 * The {@see SnapshotService} (added in PR 10)
 * orchestrates take/restore; this model is the persistent record.
 *
 * @property int $id
 * @property string $site_id
 * @property string $destination 'local_disk' | 's3'
 * @property string|null $s3_bucket
 * @property string|null $s3_key
 * @property string|null $local_path
 * @property int|null $bytes
 * @property string $engine 'mysql' | 'mariadb' | 'postgres' | 'sqlite'
 * @property string $reason 'manual' | 'pre_migration_rollback' | 'pre_destructive_command' | 'scheduled'
 * @property string $status 'pending' | 'completed' | 'failed'
 * @property string|null $error_message
 * @property string|null $taken_by_user_id
 * @property Carbon|null $expires_at
 * @property string $destination
 * @property string $engine
 * @property string $reason
 * @property string $status
 * @property-read ?Site $site
 * @property-read ?User $takenByUser
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Snapshot extends Model
{
    /** @use HasFactory<SnapshotFactory> */
    use HasFactory;

    protected $table = 'snapshots';

    protected $fillable = [
        'site_id',
        'destination',
        's3_bucket',
        's3_key',
        'local_path',
        'bytes',
        'engine',
        'reason',
        'status',
        'error_message',
        'taken_by_user_id',
        'expires_at',
    ];

    public const DESTINATION_LOCAL_DISK = 'local_disk';

    public const DESTINATION_S3 = 's3';

    /** Queued and dumping — the row exists but the dump hasn't landed yet. */
    public const STATUS_PENDING = 'pending';

    /** Dump captured and persisted to its destination. */
    public const STATUS_COMPLETED = 'completed';

    /** The dump or its upload failed; see error_message. */
    public const STATUS_FAILED = 'failed';

    public const REASON_MANUAL = 'manual';

    public const REASON_PRE_MIGRATION_ROLLBACK = 'pre_migration_rollback';

    public const REASON_PRE_DESTRUCTIVE_COMMAND = 'pre_destructive_command';

    public const REASON_SCHEDULED = 'scheduled';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function takenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** A snapshot is terminal once it has either completed or failed. */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    protected static function newFactory(): SnapshotFactory
    {
        return SnapshotFactory::new();
    }
}
