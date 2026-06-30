<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $event
 * @property string $ip_address
 * @property array<string, mixed> $meta
 * @property ?string $server_id
 * @property ?string $user_id
 * @property-read ?Server $server
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerDatabaseAuditEvent extends Model
{
    use HasUlids;

    public const EVENT_DATABASE_CREATED = 'database_created';

    public const EVENT_DATABASE_REMOVED_DPLY = 'database_removed_dply';

    public const EVENT_DATABASE_DROPPED_REMOTE = 'database_dropped_remote';

    /**
     * Operator changed which engine is the server's primary/default
     * (the {@see ServerDatabaseEngine::$is_default} flag). The {@see meta}
     * payload carries the chosen `engine`. New sites default their
     * `database_engine` to this engine.
     */
    public const EVENT_DEFAULT_ENGINE_CHANGED = 'default_engine_changed';

    /**
     * Operator changed metadata or SQLite file path on a tracked
     * database via the unified Edit modal. The {@see meta} payload
     * carries the diff (e.g. `description`, `mysql_charset`, `host`).
     */
    public const EVENT_DATABASE_UPDATED = 'database_updated';

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
