<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $host
 * @property string $password
 * @property ?string $server_database_id
 * @property string $username
 * @property-read ?ServerDatabase $serverDatabase
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }

    /** @return BelongsTo<ServerDatabase, $this> */
    public function serverDatabase(): BelongsTo
    {
        return $this->belongsTo(ServerDatabase::class, 'server_database_id');
    }
}
