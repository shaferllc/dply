<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return match ($this->engine) {
            'postgres' => 5432,
            default => 3306,
        };
    }

    /**
     * Application connection URL (credentials are decrypted; use only in trusted UI).
     */
    public function connectionUrl(): string
    {
        $user = rawurlencode((string) $this->username);
        $pass = rawurlencode((string) $this->password);
        $host = $this->host ?: '127.0.0.1';
        $port = $this->defaultPort();
        $name = $this->name;

        return match ($this->engine) {
            'postgres' => "postgresql://{$user}:{$pass}@{$host}:{$port}/{$name}",
            default => "mysql://{$user}:{$pass}@{$host}:{$port}/{$name}",
        };
    }
}
