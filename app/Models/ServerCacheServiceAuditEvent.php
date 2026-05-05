<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for the Caches workspace. One row per significant operator
 * action — install / uninstall / restart / stop / start / flush. Rendered
 * as a list on the workspace's Advanced tab.
 */
class ServerCacheServiceAuditEvent extends Model
{
    use HasUlids;

    public const EVENT_INSTALLED = 'cache_service_installed';

    public const EVENT_INSTALL_FAILED = 'cache_service_install_failed';

    public const EVENT_UNINSTALLED = 'cache_service_uninstalled';

    public const EVENT_UNINSTALL_FAILED = 'cache_service_uninstall_failed';

    public const EVENT_RESTARTED = 'cache_service_restarted';

    public const EVENT_STOPPED = 'cache_service_stopped';

    public const EVENT_STARTED = 'cache_service_started';

    public const EVENT_FLUSHED = 'cache_service_flushed';

    public const EVENT_AUTH_SET = 'cache_service_auth_set';

    public const EVENT_AUTH_CLEARED = 'cache_service_auth_cleared';

    public const EVENT_CONFIG_EDITED = 'cache_service_config_edited';

    public const EVENT_MEMORY_UPDATED = 'cache_service_memory_updated';

    public const EVENT_SWITCHED = 'cache_service_switched';

    public const EVENT_SWITCH_FAILED = 'cache_service_switch_failed';

    protected $table = 'server_cache_service_audit_events';

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
