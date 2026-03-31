<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerSystemLogs;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerLogPin;
use App\Models\Site;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function downloadDplyAuditJson(): StreamedResponse
    {
        $this->authorize('view', $this->server);

        if ($this->logKey !== 'dply_activity') {
            abort(422, __('Select Dply activity as the log source first.'));
        }

        $server = $this->server->fresh();
        if ($server->organization_id === null) {
            abort(422, __('No organization for this server.'));
        }

        $siteIds = Site::query()->where('server_id', $server->id)->pluck('id');

        $logs = AuditLog::query()
            ->where('organization_id', $server->organization_id)
            ->where(function ($q) use ($server, $siteIds) {
                $q->where(function ($q2) use ($server) {
                    $q2->where('subject_type', Server::class)
                        ->where('subject_id', $server->id);
                });
                if ($siteIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($siteIds) {
                        $q2->where('subject_type', Site::class)
                            ->whereIn('subject_id', $siteIds);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5000)
            ->with('user')
            ->get();

        $filename = 'dply-audit-'.$server->id.'-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(function () use ($logs) {
            $payload = $logs->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at?->toIso8601String(),
                'action' => $log->action,
                'user' => $log->user?->only(['id', 'name', 'email']),
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_summary' => $log->subject_summary,
            ]);

            echo $payload->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public function downloadVisibleCsv(): StreamedResponse
    {
        $this->authorize('view', $this->server);

        $text = (string) ($this->remoteLogOutput ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $filename = 'log-lines-'.$this->server->id.'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($lines) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['line']);
            foreach ($lines as $line) {
                fputcsv($out, [$line]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-logs', [
            'logSources' => $this->availableLogSources(),
            'logBroadcastEchoSubscribable' => $this->logBroadcastEchoSubscribable(),
            'logPresets' => $this->logPresetsList(),
            'logPins' => ServerLogPin::query()
                ->where('server_id', $this->server->id)
                ->where('user_id', auth()->id())
                ->where('log_key', $this->logKey)
                ->orderByDesc('created_at')
                ->get(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
