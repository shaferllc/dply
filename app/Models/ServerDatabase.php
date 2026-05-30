<?php

namespace App\Models;

use App\Support\Servers\DatabaseWorkspaceEngines;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ServerDatabase extends Model
{
    use HasUlids;

    protected $table = 'server_databases';

    protected $fillable = [
        'server_id',
        'name',
        'engine',
        'username',
        'password',
        'host',
        'description',
        'mysql_charset',
        'mysql_collation',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function extraUsers(): HasMany
    {
        return $this->hasMany(ServerDatabaseExtraUser::class, 'server_database_id');
    }

    public function credentialShares(): HasMany
    {
        return $this->hasMany(ServerDatabaseCredentialShare::class, 'server_database_id');
    }

    public function backups(): HasMany
    {
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
     */
    public function connectionUrl(): string
    {
        if ($this->engine === 'sqlite') {
            // For SQLite, `host` stores the absolute file path on the
            // server. PDO's connection string for SQLite is
            // "sqlite:/path/to/file.db".
            return 'sqlite:'.($this->host ?: '/var/lib/dply/sqlite/'.$this->name.'.db');
        }

        $user = rawurlencode((string) $this->username);
        $pass = rawurlencode((string) $this->password);
        $host = $this->host ?: '127.0.0.1';
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
