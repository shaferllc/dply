<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

if (! function_exists('reverb_health_check_url')) {
    /**
     * HTTP URL to the Reverb server's health endpoint (same process as WebSockets).
     * Opens in a new tab from the admin menu; requires Reverb to be running.
     */
    function reverb_health_check_url(): ?string
    {
        $server = config('reverb.servers.'.config('reverb.default', 'reverb'));
        if (! is_array($server)) {
            return null;
        }

        $port = (int) ($server['port'] ?? 8080);
        $host = (string) ($server['hostname'] ?? '');
        if ($host === '' || $host === '0.0.0.0') {
            $host = '127.0.0.1';
        }

        return 'http://'.$host.':'.$port.'/up';
    }
}

if (! function_exists('server_workspace_nav_item_url')) {
    /**
     * URL for a server workspace sidebar item (handles settings default tab segment).
     */
    function server_workspace_nav_item_url(Server $server, array $item): string
    {
        $routeName = $item['route'] ?? '';

        if ($routeName === 'servers.settings') {
            return route('servers.settings', ['server' => $server, 'section' => 'connection']);
        }

        return route($routeName, $server);
    }
}

if (! function_exists('audit_log')) {
    /**
     * Log an action to the organization audit log.
     */
    function audit_log(
        Organization $organization,
        ?User $user,
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        return AuditLog::log($organization, $user, $action, $subject, $oldValues, $newValues);
    }
}
