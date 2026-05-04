<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Database snapshot of a Site's data, taken either manually or as the
 * automatic safety net before a destructive operation.
 *
 * Stored either to local server disk (transient, TTL via expires_at) or
 * to a BYO S3-compatible bucket configured at the org level (durable).
 * The {@see \App\Services\Snapshots\SnapshotService} (added in PR 10)
 * orchestrates take/restore; this model is the persistent record.
 *
 * @property int $id
 * @property string $site_id
 * @property string $destination     'local_disk' | 's3'
 * @property string|null $s3_bucket
 * @property string|null $s3_key
 * @property string|null $local_path
 * @property int|null $bytes
 * @property string $engine          'mysql' | 'mariadb' | 'postgres' | 'sqlite'
 * @property string $reason          'manual' | 'pre_migration_rollback' | 'pre_destructive_command' | 'scheduled'
 * @property string|null $taken_by_user_id
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class Snapshot extends Model
{
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
        'taken_by_user_id',
        'expires_at',
    ];

    public const DESTINATION_LOCAL_DISK = 'local_disk';

    public const DESTINATION_S3 = 's3';

    public const REASON_MANUAL = 'manual';

    public const REASON_PRE_MIGRATION_ROLLBACK = 'pre_migration_rollback';

    public const REASON_PRE_DESTRUCTIVE_COMMAND = 'pre_destructive_command';

    public const REASON_SCHEDULED = 'scheduled';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'bytes' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function takenByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    protected static function newFactory(): SnapshotFactory
    {
        return SnapshotFactory::new();
    }
}
