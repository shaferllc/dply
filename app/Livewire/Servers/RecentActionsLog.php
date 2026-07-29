<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Models\ServerManageAction;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lists recent install / service / manage actions for a server with
 * click-to-view persisted SSH output. Lives on the Services page
 * (nested) and Maintenance (standalone card).
 */
class RecentActionsLog extends Component
{
    use WithPagination;

    public string $serverId = '';

    public bool $nested = false;

    public ?string $openLogId = null;

    public bool $showOpenModal = false;

    public function mount(Server $server, bool $nested = false): void
    {
        $this->serverId = (string) $server->getKey();
        $this->nested = $nested;
    }

    public function viewLog(string $id): void
    {
        $row = ServerManageAction::query()
            ->where('server_id', $this->serverId)
            ->whereKey($id)
            ->first();

        if ($row === null) {
            return;
        }

        $this->openLogId = $row->id;
        $this->showOpenModal = true;
    }

    public function closeLog(): void
    {
        $this->openLogId = null;
        $this->showOpenModal = false;
    }

    public function render(): View
    {
        $rows = ServerManageAction::query()
            ->where('server_id', $this->serverId)
            ->latest('id')
            ->paginate(15, pageName: 'actionLogs');

        $openLog = $this->openLogId !== null
            ? ServerManageAction::query()
                ->where('server_id', $this->serverId)
                ->whereKey($this->openLogId)
                ->first()
            : null;

        return view('livewire.servers.recent-actions-log', [
            'rows' => $rows,
            'openLog' => $openLog,
            'nested' => $this->nested,
        ]);
    }
}
