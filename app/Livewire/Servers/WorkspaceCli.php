<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Support\Cli\DplyCliCommandCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Server CLI reference. Deliberately NOT #[Lazy]: the page is a static command
 * card with no queries or remote calls behind it, so a deferred render buys
 * nothing and costs a second round-trip — which showed up as a multi-second
 * skeleton, and as a dead skeleton whenever the hydrate request 404'd on a
 * page loaded before workspace.cli went live.
 */
#[Layout('layouts.app')]
class WorkspaceCli extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.cli';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        $catalog = DplyCliCommandCatalog::forServer($this->server->id);

        return view('livewire.servers.workspace-cli', [
            'server' => $this->server,
            'cliGroups' => $catalog['groups'],
            'cliEntries' => $catalog['entries'],
            'cliTotal' => $catalog['total'],
        ]);
    }
}
