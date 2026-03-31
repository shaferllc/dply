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
    }
}
