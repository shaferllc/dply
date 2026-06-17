<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseEngineAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * Writes a row into `server_database_engine_audit_events` for each engine-level operation. Mirrors
 * {@see CacheServiceAuditLogger} so the two workspaces audit consistently and any future
 * org-wide audit consumers don't need workspace-specific casing.
 */
class DatabaseEngineAuditLogger
{
    /**
     * @param  array<string, mixed> $meta
     */
    public function record(
        Server $server,
        string $event,
        array $meta = [],
        ?User $user = null,
        ?string $ipAddress = null,
    ): void {
        ServerDatabaseEngineAuditEvent::query()->create([
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
