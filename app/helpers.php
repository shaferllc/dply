<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;

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

if (! function_exists('server_workspace_nav_for_server')) {
    /**
     * Server workspace sidebar items filtered to those whose backing service is installed.
     * Items without `requires_any_tags` always show. Items with it only show if at least
     * one tag is in {@see ServerInstalledServices::tagsFor}. Fails open when the provision
     * stack summary is unavailable so freshly-imported servers still see every tab.
     *
     * @return list<array<string, mixed>>
     */
    function server_workspace_nav_for_server(Server $server): array
    {
        $items = config('server_workspace.nav', []);
        if (! is_array($items)) {
            return [];
        }

        $installed = ServerInstalledServices::tagsFor($server);
        $unknownStack = array_key_exists('unknown', $installed);
        $hostKind = (string) ($server->meta['host_kind'] ?? 'vm');

        $filtered = array_filter($items, static function ($item) use ($installed, $unknownStack, $hostKind): bool {
            if (! is_array($item)) {
                return false;
            }

            // Host-kind gating first — drops VM-shaped items for K8s servers
            // (PHP, Webserver, Databases, Caches, Cron, etc.) and conversely
            // keeps the Cluster page off non-K8s servers.
            $onlyHostKinds = $item['only_host_kinds'] ?? null;
            if (is_array($onlyHostKinds) && $onlyHostKinds !== [] && ! in_array($hostKind, $onlyHostKinds, true)) {
                return false;
            }
            $exceptHostKinds = $item['except_host_kinds'] ?? null;
            if (is_array($exceptHostKinds) && in_array($hostKind, $exceptHostKinds, true)) {
                return false;
            }

            $feature = $item['feature'] ?? null;
            if (is_string($feature) && $feature !== '' && ! Feature::active($feature)) {
                return false;
            }

            $required = $item['requires_any_tags'] ?? null;
            if (! is_array($required) || $required === []) {
                return true;
            }
            if ($unknownStack) {
                return true;
            }
            foreach ($required as $tag) {
                if (is_string($tag) && array_key_exists($tag, $installed)) {
                    return true;
                }
            }

            return false;
        });

        // Daemons is visible before Supervisor is installed (the page itself
        // offers the install CTA). Surface a "needs_setup" flag so the
        // sidebar can render a small indicator until the package is in place.
        $needsSupervisorSetup = $server->supervisor_package_status !== Server::SUPERVISOR_PACKAGE_INSTALLED;
        $filtered = array_map(static function (array $item) use ($needsSupervisorSetup): array {
            if (($item['key'] ?? null) === 'daemons' && $needsSupervisorSetup) {
                $item['needs_setup'] = true;
            }

            return $item;
        }, $filtered);

        return array_values($filtered);
    }
}

if (! function_exists('multi_surface_active')) {
    /**
     * True when the current org has at least one non-VM product surface
     * enabled (Cloud / Edge / Serverless). Used to gate the Infrastructure
     * dashboard and the Launchpad — those screens are designed to triage
     * across multiple surfaces and become noise when only Servers exist.
     *
     * Optional $organization scopes the check to a specific org (admin
     * tooling); omit to use Pennant's default scope (current org).
     */
    function multi_surface_active(?Organization $organization = null): bool
    {
        foreach (['surface.cloud', 'surface.edge', 'surface.serverless'] as $flag) {
            $active = $organization === null
                ? Feature::active($flag)
                : Feature::for($organization)->active($flag);
            if ($active) {
                return true;
            }
        }

        return false;
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
