<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetricSnapshot extends Model
{
    protected $fillable = [
        'server_id',
        'captured_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
