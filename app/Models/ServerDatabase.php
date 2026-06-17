<?php

namespace App\Models;

use App\Support\Servers\DatabaseWorkspaceEngines;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 */

class ServerDatabase extends Model
{
    use HasUlids;

    protected $table = 'server_databases';

    protected $fillable = [
        'server_id',
        'site_id',
        'name',
        'engine',
        'username',
        'password',
        'host',
        'description',
        'mysql_charset',
        'mysql_collation',
        'remote_access',
        'allowed_from',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'remote_access' => 'boolean',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /**
     * The site that owns this database, if any. Server-wide databases
     * (created from the server-level manager) have a null site_id. *
 * @return BelongsTo<Site, $this>
 */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<ServerDatabaseExtraUser, $this> */
    public function extraUsers(): HasMany {
        return $this->hasMany(ServerDatabaseExtraUser::class, 'server_database_id');
    }

    /** @return HasMany<ServerDatabaseCredentialShare, $this> */
    public function credentialShares(): HasMany {
        return $this->hasMany(ServerDatabaseCredentialShare::class, 'server_database_id');
    }

    /** @return HasMany<ServerDatabaseBackup, $this> */
    public function backups(): HasMany {
        return $this->hasMany(ServerDatabaseBackup::class, 'server_database_id');
    }

    public function defaultPort(): int
    {
        return DatabaseWorkspaceEngines::defaultPortForEngine($this->engine);
    }

    /**
     * Letters + digits only so mysql/postgresql URLs stay readable (no % escapes).
     */
    public static function generateConnectionSafePassword(int $length = 32): string
    {
        return Str::password($length, symbols: false);
    }

    /**
     * Application connection URL (credentials are decrypted; use only in trusted UI).
     *
     * Pass $hostOverride to target the database over a different address than
     * the stored host — e.g. a peer server's private IP when the consuming
     * site lives on a different box in the same private network.
     */
    public function connectionUrl(?string $hostOverride = null): string
    {
        if ($this->engine === 'sqlite') {
            // For SQLite, `host` stores the absolute file path on the
            // server. PDO's connection string for SQLite is
            // "sqlite:/path/to/file.db".
            return 'sqlite:'.($this->host ?: '/var/lib/dply/sqlite/'.$this->name.'.db');
        }

        $user = rawurlencode((string) $this->username);
        $pass = rawurlencode((string) $this->password);
        $host = $hostOverride ?: ($this->host ?: '127.0.0.1');
        $port = $this->defaultPort();
        $name = $this->name;

        return match ($this->engine) {
            'postgres' => "postgresql://{$user}:{$pass}@{$host}:{$port}/{$name}",
            'mongodb' => "mongodb://{$user}:{$pass}@{$host}:{$port}/{$name}?authSource={$name}",
            'clickhouse' => "clickhouse://{$user}:{$pass}@{$host}:{$port}/{$name}",
            default => "mysql://{$user}:{$pass}@{$host}:{$port}/{$name}",
        };
    }
}
