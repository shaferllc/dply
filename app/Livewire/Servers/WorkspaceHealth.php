<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesHealthNotifications;
use App\Models\Server;
use App\Services\Servers\ServerHealthCockpit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

/**
 * VM server health & capacity cockpit — rolls up metrics, release pressure,
 * deploy failures, certificate expiry, and daemon drift in one view.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceHealth extends Component
{
    use RendersWorkspacePlaceholder;
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use CreatesNotificationChannelInline;
    use ManagesHealthNotifications;

    protected string $requiredFeature = 'workspace.health';

    /** @var list<string> */
    public const HEALTH_TABS = ['overview', 'capacity', 'releases', 'reliability', 'notifications'];

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

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->healthTab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    public function render(ServerHealthCockpit $cockpit): View
    {
        if (in_array('health', config('server_workspace.coming_soon_keys', []), true)) {
            return view('livewire.servers.workspace-health-preview', ['server' => $this->server]);
        }

        $report = $cockpit->forServer($this->server);
        $onNotificationsTab = $this->healthTab === 'notifications';

        return view('livewire.servers.workspace-health', [
            'report' => $report,
            'pollSeconds' => (int) config('server_health.ui.poll_seconds', 60),
            'notifChannels' => $onNotificationsTab ? $this->assignableHealthNotificationChannels() : collect(),
            'notifSubscriptions' => $onNotificationsTab ? $this->healthNotificationSubscriptions() : collect(),
            'notifEventLabels' => $onNotificationsTab ? $this->healthEventLabels() : [],
        ]);
    }
}
