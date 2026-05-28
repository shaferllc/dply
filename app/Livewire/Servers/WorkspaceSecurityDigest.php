<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerSecurityDigestScan;
use App\Models\Server;
use App\Services\Servers\ServerSecurityDigest;
use Illuminate\Contracts\View\View;
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

    public function render(ServerSecurityDigest $digest): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-security-digest', [
            'report' => $digest->forServer($this->server),
        ]);
    }
}
