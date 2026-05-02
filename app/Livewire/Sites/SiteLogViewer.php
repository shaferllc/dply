<?php

namespace App\Livewire\Sites;

use App\Livewire\Servers\Concerns\ManagesServerSystemLogs;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class SiteLogViewer extends Component
{
    use ManagesServerSystemLogs;

    public Server $server;

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

    public ?string $preferredLogKey = null;

    public function mount(Server $server, Site $site, ?string $preferredLogKey = null): void
    {
        $this->server = $server;
        $this->scopedSite = $site;
        $this->preferredLogKey = $preferredLogKey;
        if (is_string($preferredLogKey) && $preferredLogKey !== '') {
            $this->logKey = $preferredLogKey;
        }
        $this->bootServerLogs();
    }

    #[On('server-workspace-log-snapshot')]
    public function onServerWorkspaceLogSnapshot(mixed $payload = []): void
    {
        $this->authorize('view', $this->server);
        $this->authorize('view', $this->scopedSite);
        $data = is_array($payload) ? $payload : [];
        $this->mergeRemoteLogFromBroadcast($data);
    }

    public function render(): View
    {
        $this->server->refresh();

        return view('livewire.sites.site-log-viewer', [
            'logSources' => $this->availableLogSources(),
            'logBroadcastEchoSubscribable' => $this->logBroadcastEchoSubscribable(),
            'site' => $this->scopedSite,
        ]);
    }
}
