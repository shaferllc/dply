<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerDatabaseAdminCredential extends Model
{
    use HasUlids;

    protected $table = 'server_database_admin_credentials';

    protected $fillable = [
        'server_id',
        'mysql_root_username',
        'mysql_root_password',
        'postgres_superuser',
        'postgres_password',
        'postgres_use_sudo',
    ];

    protected function casts(): array
    {
        return [
            'mysql_root_password' => 'encrypted',
            'postgres_password' => 'encrypted',
            'postgres_use_sudo' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
