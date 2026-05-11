<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Last-observed /etc/passwd snapshot for a server's regular Linux accounts.
 * One row per (server, username). Written by the system-users sync; read by
 * the workspace page so the table survives navigation without a fresh probe.
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

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'groups' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
