<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * One row per (server, php_version) — PHP OPcache lives per PHP version, not
 * per site. The row drives `ServerOpcacheConfigEditor`, which renders
 * `opcache.ini` from the structured fields and ships it to the host.
 */
class ServerPhpOpcacheProfile extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const JIT_OFF = 'off';

    public const JIT_TRACING = 'tracing';

    public const JIT_FUNCTION = 'function';

    /** @var list<string> */
    public const JIT_MODES = [self::JIT_OFF, self::JIT_TRACING, self::JIT_FUNCTION];

    protected $table = 'server_php_opcache_profiles';

    protected $fillable = [
        'server_id',
        'php_version',
        'enabled',
        'memory_consumption_mb',
        'interned_strings_buffer_mb',
        'max_accelerated_files',
        'validate_timestamps',
        'revalidate_freq',
        'jit',
        'jit_buffer_size_mb',
        'extra_ini_raw',
        'status',
        'last_applied_at',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'memory_consumption_mb' => 'integer',
            'interned_strings_buffer_mb' => 'integer',
            'max_accelerated_files' => 'integer',
            'validate_timestamps' => 'boolean',
            'revalidate_freq' => 'integer',
            'jit_buffer_size_mb' => 'integer',
            'last_applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /**
     * Default profile for a freshly-installed PHP version. Kept here so the
     * install path and the seeder share one shape.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'memory_consumption_mb' => 128,
            'interned_strings_buffer_mb' => 16,
            'max_accelerated_files' => 10000,
            'validate_timestamps' => true,
            'revalidate_freq' => 2,
            'jit' => self::JIT_OFF,
            'jit_buffer_size_mb' => 0,
            'extra_ini_raw' => null,
        ];
    }
}
