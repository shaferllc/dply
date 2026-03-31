<?php

namespace App\Services\Servers;

use App\Models\ApiToken;
use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\User;

class ServerFirewallAuditLogger
{
    public function record(
        Server $server,
        string $event,
        array $meta = [],
        ?User $user = null,
        ?ApiToken $apiToken = null
    ): void {
        ServerFirewallAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $user?->id,
            'api_token_id' => $apiToken?->id,
            'event' => $event,
            'meta' => $meta !== [] ? $meta : null,
        ]);

        $org = $server->organization;
        if ($org && $user) {
            audit_log($org, $user, 'server.firewall.'.$event, $server, null, $meta);
        }
    }
}
