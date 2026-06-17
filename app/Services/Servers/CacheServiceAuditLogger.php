<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheServiceAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * Writes a row into `server_cache_service_audit_events` for every cache-service action and (when
 * an actor is known) also forwards through the org-wide `audit_log()` helper. Mirrors
 * `ServerDatabaseAuditLogger` so the two workspaces audit consistently.
 */
class CacheServiceAuditLogger
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
        ServerCacheServiceAuditEvent::query()->create([
            'server_id' => $server->id,
            'user_id' => $user?->id,
            'event' => $event,
            'meta' => $meta !== [] ? $meta : null,
            'ip_address' => $ipAddress ?? Request::ip(),
        ]);

        $org = $server->organization;
        if ($org instanceof Organization && $user) {
            audit_log($org, $user, 'server.caches.'.$event, $server, null, $meta);
        }
    }
}
