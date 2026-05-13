<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (server, engine). Coexistence rules: at most one row from the redis-family
 * ({@see ServerCacheService::FAMILY_REDIS_ENGINES}) per server, plus optionally one Memcached
 * row. Enforced at three layers:
 *
 *   1. DB: `unique(server_id, engine)` + a Postgres partial unique index keyed on `server_id`
 *      where `engine IN (redis,valkey,keydb,dragonfly)`. See the
 *      `collapse_cache_services_to_one_per_family` migration.
 *   2. Model: {@see ServerCacheService::familyOf()} centralises the family check the install
 *      action consumes.
 *   3. Livewire action: `installCacheService()` refuses a second redis-family install on the
 *      same server, pointing the operator at Uninstall or the engine-switch flow instead.
 *
 * The `name` column survives only for legacy data (always `'default'` going forward) and is not
 * exposed in the UI — multi-instance support was removed once it became clear most operators
 * never used it and the templated systemd-unit machinery was producing more bugs than value.
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
    public const ENGINES = ['redis', 'valkey', 'memcached', 'keydb', 'dragonfly', 'varnish'];

    /**
     * Engines that speak the Redis wire protocol on port 6379. dply allows at most one of these
     * to be installed on a given server — operators who want Valkey instead of Redis use the
     * engine-switch flow ({@see \App\Jobs\SwitchCacheServiceJob}), not parallel installs.
     *
     * @var list<string>
     */
    public const FAMILY_REDIS_ENGINES = ['redis', 'valkey', 'keydb', 'dragonfly'];

    /**
     * Family identifier the coexistence rule keys on. `redis-family` covers Redis / Valkey /
     * KeyDB / Dragonfly (all RESP-on-6379); `memcached` is its own family because the protocol
     * + port are different and the two can legitimately coexist on one server. `http-front`
     * covers reverse-proxy HTTP cache daemons (Varnish today) — they own port 80 with the
     * webserver moved to 127.0.0.1:8080, and coexist with redis-family + memcached.
     */
    public const FAMILY_REDIS = 'redis-family';

    public const FAMILY_MEMCACHED = 'memcached';

    public const FAMILY_HTTP_FRONT = 'http-front';

    /**
     * Legacy single-instance name kept only so historical rows compare equal under the new
     * one-row-per-engine rule. New rows are always inserted with `name = 'default'`.
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

    /**
     * Single-instance-per-(server,engine) is the invariant since the
     * `collapse_cache_services_to_one_per_family` migration — every row is
     * the "default" instance for its engine. Re-added to support the
     * workspace view's `@if (! $row->isDefaultInstance())` guards that
     * still ship in `resources/views/livewire/servers/workspace-caches.blade.php`
     * but were orphaned when the method was deleted in commit 60f4703 (wip).
     */
    public function isDefaultInstance(): bool
    {
        return ((string) $this->name) === self::DEFAULT_INSTANCE_NAME;
    }

    public static function defaultPortFor(string $engine): int
    {
        return match ($engine) {
            'memcached' => 11211,
            'varnish' => 80, // Varnish fronts the webserver on :80; backend moves to :8080.
            default => 6379, // redis / valkey / keydb / dragonfly all wire-compatible on 6379
        };
    }

    /**
     * Family identifier for the coexistence rule — `'redis-family'` for Redis / Valkey / KeyDB /
     * Dragonfly (all wire-compatible on port 6379, so at most one can run), `'memcached'` for
     * Memcached (different protocol + port, can coexist with a redis-family engine).
     *
     * Centralised here so the install action, the migration, and the engine-switch trigger all
     * compute the family the same way. Throws for unknown engines because a silent fall-through
     * would let an arbitrary new engine through the install gate.
     */
    public static function familyOf(string $engine): string
    {
        return match ($engine) {
            'redis', 'valkey', 'keydb', 'dragonfly' => self::FAMILY_REDIS,
            'memcached' => self::FAMILY_MEMCACHED,
            'varnish' => self::FAMILY_HTTP_FRONT,
            default => throw new \InvalidArgumentException("Unknown cache engine: {$engine}"),
        };
    }

    /**
     * HTTP-front engines (Varnish today). They sit in front of the webserver
     * and require the backend to move off port 80 — surface this so callers
     * can branch on it (e.g. webserver-switch preflight, port-allocation).
     */
    public static function isHttpFrontEngine(string $engine): bool
    {
        return self::familyOf($engine) === self::FAMILY_HTTP_FRONT;
    }
}
