<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerSecurityDigestScan;
use App\Models\Server;
use App\Services\Servers\ServerSecurityDigest;
use App\Services\Servers\ServerSshAccessGraph;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSecurityDigest extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use RunsServerSecurityDigestScan;

    protected string $requiredFeature = 'workspace.security_digest';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);
    }

    public function render(ServerSecurityDigest $digest, ServerSshAccessGraph $accessGraph): View
    {
        $this->server->refresh();

        $sshAccess = null;
        if (Feature::active('workspace.ssh_access_graph')) {
            $accessReport = $accessGraph->forServer($this->server);
            $sshAccess = [
                'overall' => $accessReport['overall'] ?? 'ok',
                'total_keys' => (int) ($accessReport['summary']['total'] ?? 0),
                'review_overdue' => (int) ($accessReport['summary']['review_overdue'] ?? 0),
                'active_sessions' => (int) ($accessReport['summary']['active_sessions'] ?? 0),
                'never_synced' => (int) ($accessReport['summary']['never_synced'] ?? 0),
            ];
        }

        return view('livewire.servers.workspace-security-digest', [
            'report' => $digest->forServer($this->server),
            'sshAccess' => $sshAccess,
            'sshAccessEnabled' => Feature::active('workspace.ssh_access_graph'),
        ]);
    }
}
