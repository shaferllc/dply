<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerSshKeyAuditEvent extends Model
{
    use HasUlids;

    public const EVENT_KEY_CREATED = 'key_created';

    public const EVENT_KEY_UPDATED = 'key_updated';

    public const EVENT_KEY_DELETED = 'key_deleted';

    public const EVENT_SYNC_COMPLETED = 'sync_completed';

    public const EVENT_SYNC_BLOCKED = 'sync_blocked';

    public const EVENT_BULK_IMPORTED = 'bulk_imported';

    public const EVENT_ORG_KEY_DEPLOYED = 'org_key_deployed';

    public const EVENT_TEAM_KEY_DEPLOYED = 'team_key_deployed';

    protected $fillable = [
        'server_id',
        'user_id',
        'event',
        'ip_address',
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
}
