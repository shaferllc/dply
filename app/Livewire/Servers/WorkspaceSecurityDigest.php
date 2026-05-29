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

/**
 * SSH auth failure volume, fail2ban jails, host firewall posture, and sshd
 * hardening — a read-only security digest over root SSH.
 *
 * When {@see workspace.security_digest} is off but
 * {@see workspace.security_digest_preview} is on, the canonical
 * /security-digest URL renders the coming-soon teaser in place of the full
 * workspace.
 */
#[Layout('layouts.app')]
class WorkspaceSecurityDigest extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use RunsServerSecurityDigestScan;

    protected string $requiredFeature = 'workspace.security_digest';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public function mount(Server $server): void
    {
        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

        if (! Feature::active('workspace.security_digest')) {
            if (workspace_security_digest_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->comingSoonPreview = false;
        $this->bootWorkspace($server);
    }

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }

    public function render(ServerSecurityDigest $digest, ServerSshAccessGraph $accessGraph): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-security-digest-preview');
        }

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
