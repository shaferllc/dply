<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A database engine (postgres / mysql / mariadb / etc.) installed on a
 * server. Distinct from {@see ServerDatabase}, which represents a named
 * database schema + credentials *on top of* an engine.
 *
 * One row per (server_id, engine) — multi-engine servers have multiple
 * rows, single-engine servers have one. Exactly one row per server may be
 * marked `is_default`; the Site `database_engine` field defaults to that
 * unless overridden.
 */
class ServerDatabaseEngine extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_RUNNING = 'running';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNINSTALLING = 'uninstalling';

    protected $table = 'server_database_engines';

    protected $fillable = [
        'server_id',
        'engine',
        'version',
        'is_default',
        'status',
        'port',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'port' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Default port for the named engine. Mirrors what the install scripts will configure.
     * postgres family runs on 5432; mysql/mariadb on 3306; sqlite is file-based (port irrelevant).
     */
    public static function defaultPortFor(string $engine): int
    {
        return DatabaseEngineInstallScripts::defaultPortFor($engine);
    }
}
