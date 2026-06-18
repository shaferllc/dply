<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesSecurityDigestNotifications;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerSecurityDigestScan;
use App\Models\Server;
use App\Services\Servers\ServerSecurityDigest;
use App\Services\Servers\ServerSshAccessGraph;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
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
#[Lazy]
class WorkspaceSecurityDigest extends Component
{
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use ManagesSecurityDigestNotifications;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;
    use RunsServerSecurityDigestScan;

    protected string $requiredFeature = 'workspace.security_digest';

    /** @var list<string> */
    public const DIGEST_TABS = ['overview', 'auth', 'hardening', 'notifications'];

    /** In-page tab: overview | auth | hardening | notifications. */
    #[Url(as: 'tab', except: 'overview', history: true)]
    public string $digest_tab = 'overview';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public function setDigestTab(string $tab): void
    {
        $this->digest_tab = in_array($tab, self::DIGEST_TABS, true) ? $tab : 'overview';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->digest_tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

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

        $onNotificationsTab = $this->digest_tab === 'notifications';

        return view('livewire.servers.workspace-security-digest', [
            'report' => $digest->forServer($this->server),
            'sshAccess' => $sshAccess,
            'sshAccessEnabled' => Feature::active('workspace.ssh_access_graph'),
            'notifChannels' => $onNotificationsTab ? $this->assignableSecurityDigestNotificationChannels() : collect(),
            'notifSubscriptions' => $onNotificationsTab ? $this->securityDigestNotificationSubscriptions() : collect(),
            'notifEventLabels' => $onNotificationsTab ? $this->securityDigestEventLabels() : [],
        ]);
    }
}
