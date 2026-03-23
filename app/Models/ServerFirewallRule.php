<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerFirewallRule extends Model
{
    protected $fillable = [
        'server_id',
        'port',
        'protocol',
        'action',
        'sort_order',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
