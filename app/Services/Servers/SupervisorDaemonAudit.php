<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Models\SupervisorProgramAuditLog;
use Illuminate\Support\Facades\Auth;

class SupervisorDaemonAudit
{
    public static function log(Server $server, ?SupervisorProgram $program, string $action, ?array $properties = null): void
    {
        if (! $server->organization_id) {
            return;
        }

        SupervisorProgramAuditLog::query()->create([
            'organization_id' => $server->organization_id,
            'server_id' => $server->id,
            'supervisor_program_id' => $program?->id,
            'user_id' => Auth::id(),
            'action' => $action,
            'properties' => $properties,
        ]);

        // Mirror to the main audit log so the server activity feed surfaces
        // daemon events alongside firewall/ssh/db/cache/cron events. The
        // table-specific row above keeps deeper per-program detail for the
        // Daemons → Activity tab.
        $organization = $server->organization;
        if ($organization !== null) {
            audit_log(
                $organization,
                Auth::user(),
                'server.daemons.'.$action,
                $server,
                null,
                array_filter([
                    'program_id' => $program?->id,
                    'program_slug' => $program?->slug,
                ] + ($properties ?? []), static fn ($v) => $v !== null),
            );
        }
    }
}
