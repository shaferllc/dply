<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesHealthNotifications;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use App\Services\Servers\ServerHealthCockpit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * VM server health & capacity cockpit — rolls up metrics, release pressure,
 * deploy failures, certificate expiry, and daemon drift in one view.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceHealth extends Component
{
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use ManagesHealthNotifications;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.health';

    /** @var list<string> */
    public const HEALTH_TABS = ['overview', 'capacity', 'releases', 'reliability', 'notifications'];

    #[Url(as: 'tab', except: 'overview')]
    public string $healthTab = 'overview';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->healthTab = $this->normalizeHealthTab($this->healthTab);
    }

    public function setHealthWorkspaceTab(string $tab): void
    {
        $this->healthTab = $this->normalizeHealthTab($tab);
    }

    /**
     * Coerce an unavailable tab back to Overview.
     *
     * Releases lists per-site atomic deploy folders, so it is hidden on roles
     * that host no site code. Without this a bookmarked ?tab=releases would
     * paint the tab strip with nothing under it — the tab it selected no longer
     * exists to render a panel.
     */
    protected function normalizeHealthTab(string $tab): string
    {
        if (! in_array($tab, self::HEALTH_TABS, true)) {
            return 'overview';
        }

        if ($tab === 'releases' && ! $this->hostsSiteCode()) {
            return 'overview';
        }

        return $tab;
    }

    /** Dedicated service boxes (database / cache / load balancer) never host site code. */
    public function hostsSiteCode(): bool
    {
        return ! in_array(
            (string) ($this->server->meta['server_role'] ?? ''),
            ['redis', 'valkey', 'database', 'load_balancer'],
            true,
        );
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

    /**
     * Merged Health card skeleton (hide-hero) so lazy load matches the page
     * instead of flashing a separate title card + generic pulses.
     */
    public function placeholder(): View
    {
        if ($this->server === null) {
            return view('livewire.servers.partials.workspace-placeholder-empty');
        }

        return view('livewire.servers.partials.workspace-health-placeholder', [
            'server' => $this->server,
        ]);
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
