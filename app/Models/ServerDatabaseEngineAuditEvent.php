<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * Audit trail for engine-level operations in the Databases workspace.
 * Mirrors {@see ServerCacheServiceAuditEvent} — install / uninstall flows
 * record successes and failures; the workspace's Advanced tab renders the
 * recent rows alongside the existing per-database audit log.
 */
class ServerDatabaseEngineAuditEvent extends Model
{
    use HasUlids;

    public const EVENT_ENGINE_INSTALLED = 'engine_installed';

    public const EVENT_ENGINE_INSTALL_FAILED = 'engine_install_failed';

    public const EVENT_ENGINE_UNINSTALLED = 'engine_uninstalled';

    public const EVENT_ENGINE_UNINSTALL_FAILED = 'engine_uninstall_failed';

    public const EVENT_ENGINE_ACTIVATED = 'engine_activated';

    public const EVENT_ENGINE_DEACTIVATED = 'engine_deactivated';

    public const EVENT_ENGINE_ACTIVATION_FAILED = 'engine_activation_failed';

    protected $table = 'server_database_engine_audit_events';

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
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }
}
