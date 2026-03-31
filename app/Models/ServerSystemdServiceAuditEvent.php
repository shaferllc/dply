<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerSystemdServiceAuditEvent extends Model
{
    protected $fillable = [
        'server_id',
        'occurred_at',
        'kind',
        'unit',
        'label',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
