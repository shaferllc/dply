<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Legacy Workers SLO route — merged into {@see WorkspaceDaemons}.
 */
#[Layout('layouts.app')]
class WorkspaceDaemonSlo extends Component
{
    public function mount(Server $server): void
    {
        $this->redirect(route('servers.workers', $server), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-daemon-slo');
    }
}
