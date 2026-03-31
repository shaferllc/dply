<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerFirewallApplyLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'user_id',
        'api_token_id',
        'kind',
        'success',
        'rules_hash',
        'rule_count',
        'message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'meta' => 'array',
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

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }
}
