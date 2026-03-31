<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerFirewallSnapshot extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'user_id',
        'label',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
