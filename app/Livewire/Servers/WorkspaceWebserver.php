<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;

/**
 * Top-level "Webserver" workspace — gives the per-server webserver picker grid +
 * cascade modal + audit history its own sidebar entry, peer to PHP / Caches /
 * Cron, rather than living nested under Manage > Web.
 *
 * Extends {@see WorkspaceManage} so all the switch state, switch methods,
 * service-action plumbing (runAllowlistedAction et al), banner concerns, and
 * console-action dismissal are inherited unchanged. The only differences:
 *
 *   - `mount()` accepts no `?section` query string (this isn't a sub-tab
 *     anymore) — section is fixed at 'web' so the parent's render share +
 *     trait-internal asserts continue working.
 *   - `render()` points at a dedicated `workspace-webserver.blade.php` view
 *     that wraps the group-web partial in {@see <x-server-workspace-layout>}
 *     with `active="webserver"` (sidebar highlight).
 *
 * Result: clicking "Webserver" in the sidebar lands on the same content,
 * scoped + framed as a peer workspace rather than nested.
 */
#[Layout('layouts.app')]
class WorkspaceWebserver extends WorkspaceManage
{
    /**
     * Second-level tab within this workspace — mirrors WorkspaceDatabases /
     * WorkspaceCaches: an "overview" tab, one tab per webserver in the
     * catalog (currently active gets an Active badge; the rest let the
     * operator open the cascade-switch modal), and an "advanced" tab that
     * collects PHP-FPM, TLS, and the switch-history table.
     *
     * Allowed values are validated in {@see setWorkspaceTab()}; an unknown
     * value falls back to 'overview' rather than throwing.
     */
    public string $workspace_tab = 'overview';

    /**
     * Per-engine sub-tab — flips between the action surface (`overview`) and
     * the engine information card (`info`) inside each per-webserver tab panel.
     * Same shape as the cache + database workspaces so the navigation pattern
     * is consistent across operator surfaces. Validated in
     * {@see setEngineSubtab()}; unknown values fall back to `overview`.
     */
    public string $engine_subtab = 'overview';

    public function mount(Server $server, ?string $section = null): void
    {
        // Force the inherited 'web' section state — the parent's render share
        // and any internal asserts on $section still resolve correctly without
        // requiring the operator to type `?section=web` on the URL.
        parent::mount($server, 'web');
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = ['overview', 'nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'advanced'];
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'overview';
        // Reset the sub-tab on every top-level switch so the operator always
        // lands on the actionable view first. Skipping this would leave
        // Caddy on `info` after they navigated away from Nginx's `info`.
        $this->engine_subtab = 'overview';
    }

    public function setEngineSubtab(string $subtab): void
    {
        $allowed = ['overview', 'info'];
        $this->engine_subtab = in_array($subtab, $allowed, true) ? $subtab : 'overview';
    }

    public function render(): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-webserver', [
            'configPreviews' => config('server_manage.config_previews', []),
            'serviceActions' => config('server_manage.service_actions', []),
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
            'deletionSummary' => $this->showRemoveServerModal
                ? \App\Services\Servers\ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
