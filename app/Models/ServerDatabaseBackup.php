<?php

namespace App\Models;

use App\Services\Servers\DatabaseBackupExporter;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property ?string $backup_configuration_id
 * @property string $bytes
 * @property string $disk_path
 * @property ?string $error_message
 * @property string $remote_path
 * @property string $s3_bucket
 * @property string $s3_key
 * @property ?string $server_database_id
 * @property string $status
 * @property string $storage_kind
 * @property ?string $user_id
 * @property-read ?ServerDatabase $serverDatabase
 * @property-read ?BackupConfiguration $backupConfiguration
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerDatabaseBackup extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'server_database_backups';

    protected $fillable = [
        'server_database_id',
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

    /** @return BelongsTo<ServerDatabase, $this> */
    public function serverDatabase(): BelongsTo
    {
        return $this->belongsTo(ServerDatabase::class, 'server_database_id');
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

    public function isDownloadable(): bool
    {
        return app(DatabaseBackupExporter::class)->isDownloadable($this);
    }
}
