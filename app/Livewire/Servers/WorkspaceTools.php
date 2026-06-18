<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Models\ServerManageAction;
use App\Services\Servers\ServerManageToolsReport;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;

/**
 * Top-level "Tools" workspace — promotes the former Manage > Tools sub-tab to its
 * own sidebar entry, peer to PHP / Caches / Webserver. This is the only surface
 * left with unique content after the Manage workspace was dissolved: installed
 * CLIs / version managers (Composer, Git, Docker), mise-managed language
 * runtimes, and the git deploy identity form.
 *
 * Extends {@see WorkspaceManage} (same pattern as {@see WorkspaceWebserver}) so
 * all the tool-action plumbing — runAllowlistedAction, mise runtime ops, tool
 * install/repair, remote-task polling, the confirm-action modal and console
 * banner concerns — is inherited unchanged. The differences are only:
 *
 *   - `mount()` fixes the inherited section at 'tools' (no `?section` query).
 *   - `render()` points at `workspace-tools.blade.php`, a thin tools-only
 *     orchestrator framed as a peer workspace with `active="tools"` (no Manage
 *     sub-tab strip).
 *
 * The "Host power" card (reboot + stuck-task cleanup) rides along here because
 * those actions reuse the same inherited action stack; reboot also remains on
 * the Patches workspace.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceTools extends WorkspaceManage
{
    public function mount(Server $server, ?string $section = null): void
    {
        // Force the inherited 'tools' section state — mirrors WorkspaceWebserver's
        // fixed 'web' section so the parent's render share + trait-internal state
        // resolve without the operator typing `?section=tools`.
        parent::mount($server, 'tools');
    }

    /**
     * Tools-only placeholder while the body lazy-loads: a peer workspace frame
     * with no Manage sub-tab strip (the parent's placeholder renders that strip).
     */
    public function placeholder(): View
    {
        return view('livewire.servers.partials.workspace-placeholder', [
            'server' => $this->server,
            'active' => 'tools',
            'title' => __('Tools'),
        ]);
    }

    public function render(ServerManageToolsReport $toolsReport): View
    {
        $serviceActions = config('server_manage.service_actions', []);

        return view('livewire.servers.workspace-tools', [
            'serviceActions' => $serviceActions,
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'toolsReport' => $toolsReport->build($this->server, $serviceActions),
            'activeMiseRuntimeOps' => $this->activeMiseRuntimeOperations(),
            'activeToolActionOps' => $this->activeToolActionOperations(),
            'pendingToolActionKey' => $this->pendingToolActionKey,
            'miseReprobePending' => $this->miseReprobePending,
            'toolsPanel' => $this->toolsPanel,
            'recentActions' => ServerManageAction::query()
                ->where('server_id', $this->server->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
