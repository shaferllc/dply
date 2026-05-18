<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\RunsServerConsoleCommands;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Console drawer — embedded SSH console for ANY page in the app.
 *
 * The drawer renders globally in the main layout so an operator can pop
 * open a terminal from anywhere. It accepts the route-bound server (when
 * available, e.g. on /servers/{id}/*), and otherwise restores the most
 * recently chosen server from the session. If neither is available, the
 * drawer shows a picker of the current org's ready+ssh-keyed servers.
 *
 * Shares command-running state and SSH plumbing with WorkspaceConsole via
 * the RunsServerConsoleCommands trait (same history cap, deployer guard,
 * exec timeout).
 *
 * The trimmed UX (no help sidebar, no autocomplete, no install banner) is
 * deliberate: those need horizontal space the drawer doesn't have. An
 * "Open full" link in the drawer header routes to the dedicated Console
 * page when the operator wants the full kit.
 */
class ConsoleDrawer extends Component
{
    use RunsServerConsoleCommands;

    /** Active server, or null when no pick has been made yet. */
    public ?Server $server = null;

    /** Whether the active server is verified available (not stale). */
    public bool $serverVerified = false;

    /** Loading state for server operations. */
    public bool $serverLoading = false;

    /** Last time the available servers list was refreshed. */
    public ?string $serversLastRefreshed = null;

    protected const SESSION_KEY = 'dply.consoleDrawer.serverId';

    protected $listeners = [
        'refresh-server-status' => 'verifyActiveServer',
    ];

    public function mount(?Server $server = null): void
    {
        // Route-bound server (we're on /servers/{id}/…) — preferred.
        if ($server instanceof Server) {
            $this->setActiveServer($server);
            $this->verifyActiveServer();

            return;
        }

        // Restore the operator's last pick so the drawer feels persistent
        // as they navigate between non-server pages.
        $remembered = session(self::SESSION_KEY);
        if (is_string($remembered) && $remembered !== '') {
            $s = $this->loadServerForOrg($remembered);
            if ($s) {
                $this->server = $s;
                $this->verifyActiveServer();
            }
        }
    }

    public function selectServer(string $id): void
    {
        $this->serverLoading = true;

        $server = $this->loadServerForOrg($id);
        if (! $server) {
            $this->serverLoading = false;
            $this->error = __('Server not found or not available.');

            return;
        }

        $this->setActiveServer($server);
        $this->serverVerified = true;
        $this->serverLoading = false;
        $this->error = null;
    }

    public function clearActiveServer(): void
    {
        $this->server = null;
        $this->serverVerified = false;
        $this->serverLoading = false;
        $this->history = [];
        $this->error = null;
        session()->forget(self::SESSION_KEY);
    }

    public function refreshAvailableServers(): void
    {
        $this->serversLastRefreshed = now()->toIso8601String();
        // Re-render will fetch fresh list
    }

    /**
     * Verify that the currently selected server is still available.
     * Called on mount and can be triggered manually or on error recovery.
     */
    public function verifyActiveServer(): void
    {
        if ($this->server === null) {
            return;
        }

        // Refresh from DB to check current status
        $this->server->refresh();

        if ($this->server->status !== Server::STATUS_READY) {
            $this->serverVerified = false;
            $this->error = __('Server is no longer available (status: :status). Please select a different server.', [
                'status' => $this->server->status,
            ]);

            return;
        }

        if (empty($this->server->ssh_private_key)) {
            $this->serverVerified = false;
            $this->error = __('Server SSH key is no longer configured. Please select a different server.');

            return;
        }

        $this->serverVerified = true;
    }

    /**
     * Handle the case where server becomes unavailable during drawer use.
     * Gracefully degrades to the server picker.
     */
    public function handleServerUnavailable(): void
    {
        $this->serverVerified = false;
        $this->error = __('Server connection lost. The server may be restarting or unreachable.');
    }

    protected function setActiveServer(Server $server): void
    {
        $this->authorize('view', $server);
        $this->server = $server;
        session([self::SESSION_KEY => (string) $server->id]);
    }

    protected function loadServerForOrg(string $id): ?Server
    {
        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            return null;
        }

        return Server::query()
            ->where('organization_id', $organization->id)
            ->whereKey($id)
            ->where('status', Server::STATUS_READY)
            ->whereNotNull('ssh_private_key')
            ->first();
    }

    /**
     * @return Collection<int, Server>
     */
    protected function availableServers(): Collection
    {
        $organization = auth()->user()?->currentOrganization();
        if (! $organization) {
            return collect();
        }

        return Server::query()
            ->where('organization_id', $organization->id)
            ->where('status', Server::STATUS_READY)
            ->whereNotNull('ssh_private_key')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'ip_address']);
    }

    public function render(): View
    {
        // Verify server on each render if set but not verified
        if ($this->server !== null && ! $this->serverVerified && ! $this->serverLoading) {
            $this->verifyActiveServer();
        }

        return view('livewire.servers.console-drawer', [
            // Only fetch the picker list when we need it — avoids a DB query
            // on every server-page render where the active server is set.
            'availableServers' => $this->server ? collect() : $this->availableServers(),
            'serverReady' => $this->server !== null && $this->serverVerified && ! $this->serverLoading,
        ]);
    }
}
