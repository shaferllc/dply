<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 *                      Point-in-time RDB snapshot of a redis-family cache service. Lifecycle states:
 *                      pending → completed   when the exporter wrote the file and updated bytes/s3_key
 *                      pending → failed      when SSH or upload errored (error_message populated)
 *                      Storage backends mirror {@see ServerDatabaseBackup}: 'destination' (S3-style),
 *                      'remote_server' (local tree on the box), 'control_plane' (Dply storage disk —
 *                      dev only).
 * @property ?string $backup_configuration_id
 * @property int $bytes
 * @property string $disk_path
 * @property ?string $error_message
 * @property string $remote_path
 * @property string $s3_bucket
 * @property string $s3_key
 * @property ?string $server_cache_service_id
 * @property ?string $server_id
 * @property string $status
 * @property string $storage_kind
 * @property ?string $user_id
 * @property-read ?Server $server
 * @property-read ?ServerCacheService $cacheService
 * @property-read ?BackupConfiguration $backupConfiguration
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class RedisSnapshot extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STORAGE_DESTINATION = 'destination';

    public const STORAGE_REMOTE_SERVER = 'remote_server';

    public const STORAGE_CONTROL_PLANE = 'control_plane';

    protected $table = 'redis_snapshots';

    protected $fillable = [
        'server_id',
        'server_cache_service_id',
        'user_id',
        'backup_configuration_id',
        'status',
        'storage_kind',
        'disk_path',
        'remote_path',
        's3_bucket',
        's3_key',
        'bytes',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<ServerCacheService, $this> */
    public function cacheService(): BelongsTo
    {
        return $this->belongsTo(ServerCacheService::class, 'server_cache_service_id');
    }

    /** @return BelongsTo<BackupConfiguration, $this> */
    public function backupConfiguration(): BelongsTo
    {
        return $this->belongsTo(BackupConfiguration::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
