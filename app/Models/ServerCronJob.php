<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerCronJob extends Model
{
    protected $table = 'server_cron_jobs';

    protected $fillable = [
        'server_id',
        'cron_expression',
        'command',
        'user',
        'is_synced',
        'last_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'is_synced' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
