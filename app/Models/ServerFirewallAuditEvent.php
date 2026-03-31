<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerFirewallAuditEvent extends Model
{
    use HasUlids;

    public const EVENT_RULE_CREATED = 'rule_created';

    public const EVENT_RULE_UPDATED = 'rule_updated';

    public const EVENT_RULE_DELETED = 'rule_deleted';

    public const EVENT_APPLY = 'apply';

    public const EVENT_TEMPLATE_APPLIED = 'template_applied';

    public const EVENT_IMPORT = 'import';

    public const EVENT_EXPORT = 'export';

    public const EVENT_SNAPSHOT_CREATED = 'snapshot_created';

    public const EVENT_SNAPSHOT_RESTORED = 'snapshot_restored';

    public const EVENT_SCHEDULED_APPLY = 'scheduled_apply';

    public const EVENT_SYNTHETIC_PROBE = 'synthetic_probe';

    protected $fillable = [
        'server_id',
        'user_id',
        'api_token_id',
        'event',
        'meta',
    ];

    protected function casts(): array
    {
        return [
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
