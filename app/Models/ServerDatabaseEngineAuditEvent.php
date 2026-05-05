<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
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

    protected $table = 'server_database_engine_audit_events';

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
