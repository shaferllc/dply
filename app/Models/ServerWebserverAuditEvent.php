<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\RemoteCli\RiskLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit row for a server-webserver switch (nginx → caddy, etc.).
 * Mirrors {@see SiteAuditEvent} in shape — `action`, `risk`, `transport`,
 * `summary`, `payload`, `result_status` — rather than the older
 * `event`+`meta` pattern used by ServerFirewallAuditEvent. The richer shape
 * captures what the switch flow needs (from/to, cascade opt-ins, duration).
 *
 * @property int $id
 * @property string $server_id
 * @property string|null $user_id
 * @property string $action
 * @property RiskLevel $risk
 * @property string $transport 'web' | 'cli' | 'system'
 * @property string $summary
 * @property array<string, mixed>|null $payload
 * @property string $result_status 'success' | 'failure'
 * @property Carbon $created_at
 * @property string $result_status
 * @property string $transport
 * @property-read ?Server $server
 * @property-read ?User $user
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerWebserverAuditEvent extends Model
{
    protected $table = 'server_webserver_audit_events';

    /** No updated_at — audit rows are append-only. */
    public const UPDATED_AT = null;

    public const TRANSPORT_WEB = 'web';

    public const TRANSPORT_CLI = 'cli';

    public const TRANSPORT_SYSTEM = 'system';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    public const ACTION_SWITCHED = 'server_webserver_switched';

    public const ACTION_SWITCH_FAILED = 'server_webserver_switch_failed';

    public const ACTION_ROLLBACK = 'server_webserver_rollback';

    public const ACTION_EDGE_PROXY_ADDED = 'server_edge_proxy_added';

    public const ACTION_EDGE_PROXY_REMOVED = 'server_edge_proxy_removed';

    public const ACTION_EDGE_PROXY_FAILED = 'server_edge_proxy_failed';

    protected $fillable = [
        'server_id',
        'user_id',
        'action',
        'risk',
        'transport',
        'summary',
        'payload',
        'result_status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'risk' => RiskLevel::class,
            'payload' => 'array',
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
}
