<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerHealthCockpit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * VM server health & capacity cockpit — rolls up metrics, release pressure,
 * deploy failures, certificate expiry, and daemon drift in one view.
 */
#[Layout('layouts.app')]
class WorkspaceHealth extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.health';

    /** @var list<string> */
    public const HEALTH_TABS = ['overview', 'capacity', 'releases', 'reliability'];

    #[Url(as: 'tab', except: 'overview')]
    public string $healthTab = 'overview';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function setHealthWorkspaceTab(string $tab): void
    {
        $this->healthTab = in_array($tab, self::HEALTH_TABS, true) ? $tab : 'overview';
    }

    public function render(ServerHealthCockpit $cockpit): View
    {
        if (in_array('health', config('server_workspace.coming_soon_keys', []), true)) {
            return view('livewire.servers.workspace-health-preview', ['server' => $this->server]);
        }

        $report = $cockpit->forServer($this->server);

        return view('livewire.servers.workspace-health', [
            'report' => $report,
            'pollSeconds' => (int) config('server_health.ui.poll_seconds', 60),
        ]);
    }
}
