<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'status',
        'disk_path',
        'bytes',
        'error_message',
    ];

    public function serverDatabase(): BelongsTo
    {
        return $this->belongsTo(ServerDatabase::class, 'server_database_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
