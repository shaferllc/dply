<?php

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Request;

class ServerAuthorizedKeysAuditLogger
{
    public function record(
        Server $server,
        string $event,
        array $meta = [],
        ?User $user = null,
        ?string $ipAddress = null,
    ): void {
        ServerSshKeyAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $user?->id,
            'event' => $event,
            'ip_address' => $ipAddress ?? Request::ip(),
            'meta' => $meta !== [] ? $meta : null,
        ]);

        $org = $server->organization;
        if ($org instanceof Organization && $user) {
            audit_log($org, $user, 'server.ssh_keys.'.$event, $server, null, $meta);
        }
    }
}
