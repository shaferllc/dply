<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class WorkspaceCli extends Component
{
    use InteractsWithServerWorkspace;

    public Server $server;

    public function mount(Server $server): void
    {
        $this->server = $server;
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-cli', [
            'server' => $this->server,
        ]);
    }
}
