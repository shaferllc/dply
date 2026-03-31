<?php

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Request;

class ServerDatabaseAuditLogger
{
    public function record(
        Server $server,
        string $event,
        array $meta = [],
        ?User $user = null,
        ?string $ipAddress = null,
    ): void {
        ServerDatabaseAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $user?->id,
            'event' => $event,
            'meta' => $meta !== [] ? $meta : null,
            'ip_address' => $ipAddress ?? Request::ip(),
        ]);

        $org = $server->organization;
        if ($org instanceof Organization && $user) {
            audit_log($org, $user, 'server.databases.'.$event, $server, null, $meta);
        }
    }
}
