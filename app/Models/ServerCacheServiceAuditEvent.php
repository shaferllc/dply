<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 *                      Audit trail for the Caches workspace. One row per significant operator
 *                      action — install / uninstall / restart / stop / start / flush. Rendered
 *                      as a list on the workspace's Advanced tab.
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
class ServerCacheServiceAuditEvent extends Model
{
    use HasUlids;

    public const EVENT_INSTALLED = 'cache_service_installed';

    public const EVENT_INSTALL_FAILED = 'cache_service_install_failed';

    public const EVENT_INSTALL_CANCELLED = 'cache_service_install_cancelled';

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

    public const EVENT_PORT_CHANGED = 'cache_service_port_changed';

    public const EVENT_SWITCHED = 'cache_service_switched';

    public const EVENT_SWITCH_FAILED = 'cache_service_switch_failed';

    public const EVENT_REPL_EXECUTED = 'cache_service_repl_executed';

    public const EVENT_REPL_DENIED = 'cache_service_repl_denied';

    public const EVENT_REPL_BLOCKED = 'cache_service_repl_blocked';

    public const EVENT_REPL_UNLOCKED = 'cache_service_repl_unlocked';

    public const EVENT_REPL_LOCKED = 'cache_service_repl_locked';

    public const EVENT_MONITOR_STARTED = 'cache_service_monitor_started';

    public const EVENT_MONITOR_COMPLETED = 'cache_service_monitor_completed';

    public const EVENT_MONITOR_FAILED = 'cache_service_monitor_failed';

    public const EVENT_SLOWLOG_RESET = 'cache_service_slowlog_reset';

    public const EVENT_BGSAVE = 'cache_service_bgsave';

    public const EVENT_BGREWRITEAOF = 'cache_service_bgrewriteaof';

    public const EVENT_AOF_TOGGLED = 'cache_service_aof_toggled';

    public const EVENT_RDB_SCHEDULE_SAVED = 'cache_service_rdb_schedule_saved';

    public const EVENT_REPLICA_ATTACHED = 'cache_service_replica_attached';

    public const EVENT_REPLICA_DETACHED = 'cache_service_replica_detached';

    public const EVENT_REPLICA_ATTACH_FAILED = 'cache_service_replica_attach_failed';

    public const EVENT_CACHE_PREFIX_UPDATED = 'cache_service_cache_prefix_updated';

    protected $table = 'server_cache_service_audit_events';

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
