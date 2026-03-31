<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerDatabaseExtraUser extends Model
{
    use HasUlids;

    protected $table = 'server_database_extra_users';

    protected $fillable = [
        'server_database_id',
        'username',
        'password',
        'host',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    public function serverDatabase(): BelongsTo
    {
        return $this->belongsTo(ServerDatabase::class, 'server_database_id');
    }
}
