<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property ?string $api_token_id
 * @property string $kind
 * @property string $message
 * @property array<string, mixed> $meta
 * @property string $rule_count
 * @property string $rules_hash
 * @property ?string $server_id
 * @property bool $success
 * @property ?string $user_id
 * @property-read ?Server $server
 * @property-read ?User $user
 * @property-read ?ApiToken $apiToken
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ApiToken, $this> */
    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }
}
