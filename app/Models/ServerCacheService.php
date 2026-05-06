<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single cache service (redis / valkey / memcached / keydb / dragonfly)
 * installed on a server. Backed by `server_cache_services`; one row per
 * server (the table enforces it via a unique index).
 */
class ServerCacheService extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_RUNNING = 'running';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNINSTALLING = 'uninstalling';

    /** @var list<string> */
    public const ENGINES = ['redis', 'valkey', 'memcached', 'keydb', 'dragonfly'];

    /**
     * Reserved instance name used by single-instance-per-engine rows. Install
     * scripts route this name to the engine's legacy systemd unit + legacy
     * config path so existing servers (provisioned before multi-instance) keep
     * working with no on-box changes.
     */
    public const DEFAULT_INSTANCE_NAME = 'default';

    protected $table = 'server_cache_services';

    protected $fillable = [
        'server_id',
        'engine',
        'name',
        'target_engine',
        'version',
        'status',
        'port',
        'error_message',
        'install_output',
        'cancel_requested_at',
        'auth_password',
    ];

    protected $hidden = [
        'auth_password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'cancel_requested_at' => 'datetime',
            'auth_password' => 'encrypted',
        ];
    }

    /**
     * Engines that support a `requirepass`-style auth knob. Memcached has no native auth;
     * the workspace UI hides the password card on memcached so callers shouldn't need to ask
     * but the constant lets server-side guards fail fast on a tampered payload.
     */
    public static function engineSupportsAuth(string $engine): bool
    {
        return in_array($engine, ['redis', 'valkey', 'keydb', 'dragonfly'], true);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public static function defaultPortFor(string $engine): int
    {
        return match ($engine) {
            'memcached' => 11211,
            default => 6379, // redis / valkey / keydb / dragonfly all wire-compatible on 6379
        };
    }

    /**
     * True when this row is the legacy single-instance install for its engine.
     * Install/uninstall scripts use this to pick legacy paths (e.g.
     * `/etc/redis/redis.conf` + `redis-server.service`) over templated paths
     * (`/etc/redis/redis-<name>.conf` + `redis-server@<name>.service`). Letting
     * the existing single-instance servers keep their unmoved files is the
     * point of the `default` reservation.
     */
    public function isDefaultInstance(): bool
    {
        return $this->name === self::DEFAULT_INSTANCE_NAME;
    }

    /**
     * Allowed instance-name shape for operator input. Lowercase, digits, and
     * hyphens; up to 32 chars. Used for systemd unit names and config file
     * paths so we keep it conservative (no underscores, no dots, no @).
     */
    public static function isValidInstanceName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $name) === 1;
    }
}
