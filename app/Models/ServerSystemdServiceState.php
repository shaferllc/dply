<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerSystemdServiceState extends Model
{
    protected $fillable = [
        'server_id',
        'unit',
        'label',
        'active_state',
        'sub_state',
        'unit_file_state',
        'main_pid',
        'active_enter_ts',
        'version',
        'is_custom',
        'can_manage',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
            'can_manage' => 'boolean',
            'captured_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
