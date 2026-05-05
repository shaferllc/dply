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

    protected $table = 'server_cache_services';

    protected $fillable = [
        'server_id',
        'engine',
        'version',
        'status',
        'port',
        'error_message',
        'auth_password',
    ];

    protected $hidden = [
        'auth_password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
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
}
