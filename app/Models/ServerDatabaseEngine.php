<?php

declare(strict_types=1);

namespace App\Models;

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

    protected $table = 'server_database_engines';

    protected $fillable = [
        'server_id',
        'engine',
        'version',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
