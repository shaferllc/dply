<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerSystemLogs;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceLogs extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesServerSystemLogs;

    public bool $logOptionsMenuOpen = false;

    public bool $logSourceMenuOpen = false;

    public function toggleLogSourceMenu(): void
    {
        $this->logSourceMenuOpen = ! $this->logSourceMenuOpen;
        if ($this->logSourceMenuOpen) {
            $this->logOptionsMenuOpen = false;
        }
    }

    public function closeLogSourceMenu(): void
    {
        $this->logSourceMenuOpen = false;
    }

    public function selectLogSourceFromMenu(string $key): void
    {
        $this->selectLogSource($key);
        $this->logSourceMenuOpen = false;
    }

    public function toggleLogOptionsMenu(): void
    {
        $this->logOptionsMenuOpen = ! $this->logOptionsMenuOpen;
        if ($this->logOptionsMenuOpen) {
            $this->logSourceMenuOpen = false;
        }
    }

    public function closeLogOptionsMenu(): void
    {
        $this->logOptionsMenuOpen = false;
    }

    public function applyLogViewerSettingsAndCloseMenu(): void
    {
        $this->applyLogTailLines();
        $this->closeLogOptionsMenu();
    }

    public function refreshSystemLogAndCloseMenu(): void
    {
        $this->refreshSystemLog();
        $this->closeLogOptionsMenu();
    }

    public function clearLogDisplayAndCloseMenu(): void
    {
        $this->clearLogDisplay();
        $this->closeLogOptionsMenu();
    }

    public function resetLogFilterAndCloseMenu(): void
    {
        $this->logFilter = '';
        $this->logFilterUseRegex = false;
        $this->logFilterInvert = false;
        $this->logFilterError = null;
        $this->applyLogFilterToOutput();
        $this->closeLogOptionsMenu();
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->bootServerLogs();
    }

    #[On('server-workspace-log-snapshot')]
    public function onServerWorkspaceLogSnapshot(mixed $payload = []): void
    {
        $this->authorize('view', $this->server);
        $data = is_array($payload) ? $payload : [];
        $this->mergeRemoteLogFromBroadcast($data);
    }

    public function render(): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-logs', [
            'logSources' => $this->availableLogSources(),
            'logBroadcastEchoSubscribable' => $this->logBroadcastEchoSubscribable(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
