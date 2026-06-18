<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      Last-observed /etc/passwd snapshot for a server's regular Linux accounts.
 *                      One row per (server, username). Written by the system-users sync; read by
 *                      the workspace page so the table survives navigation without a fresh probe.
 * @property array<string, mixed> $groups
 * @property string $home
 * @property ?Carbon $last_seen_at
 * @property ?string $server_id
 * @property string $shell
 * @property int $uid
 * @property string $username
 * @property-read ?Server $server
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerSystemUser extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'username',
        'uid',
        'home',
        'shell',
        'groups',
        'last_seen_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'groups' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
