<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceCli extends Component
{
    use RendersWorkspacePlaceholder;
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.cli';

    public Server $server;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-cli', [
            'server' => $this->server,
        ]);
    }
}
