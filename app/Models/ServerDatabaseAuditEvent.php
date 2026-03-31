<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerDatabaseAuditEvent extends Model
{
    use HasUlids;

    public const EVENT_DATABASE_CREATED = 'database_created';

    public const EVENT_DATABASE_REMOVED_DPLY = 'database_removed_dply';

    public const EVENT_DATABASE_DROPPED_REMOTE = 'database_dropped_remote';

    public const EVENT_SYNC_RAN = 'sync_ran';

    public const EVENT_ADMIN_CREDENTIALS_SAVED = 'admin_credentials_saved';

    public const EVENT_EXTRA_USER_CREATED = 'extra_user_created';

    public const EVENT_EXTRA_USER_REMOVED = 'extra_user_removed';

    public const EVENT_BACKUP_EXPORTED = 'backup_exported';

    public const EVENT_IMPORT_RAN = 'import_ran';

    public const EVENT_CREDENTIAL_SHARE_CREATED = 'credential_share_created';

    public const EVENT_CREDENTIAL_SHARE_VIEWED = 'credential_share_viewed';

    public const EVENT_DRIFT_CHECK = 'drift_check';

    protected $table = 'server_database_audit_events';

    protected $fillable = [
        'server_id',
        'user_id',
        'event',
        'meta',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
