<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesReleaseHygieneLogViewer;
use App\Livewire\Servers\Concerns\ManagesReleaseHygieneNotifications;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerReleaseHygieneScan;
use App\Models\Server;
use App\Services\Servers\ServerReleaseHygiene;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Release & disk hygiene — atomic release pressure, log sizes, failed jobs,
 * and a one-click prune saved-command template.
 *
 * When {@see workspace.release_hygiene} is off but
 * {@see workspace.release_hygiene_preview} is on, the canonical /hygiene URL
 * renders the coming-soon teaser in place of the full workspace.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceReleaseHygiene extends Component
{
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use ManagesReleaseHygieneLogViewer;
    use ManagesReleaseHygieneNotifications;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;
    use RunsServerReleaseHygieneScan;

    protected string $requiredFeature = 'workspace.release_hygiene';

    /** @var list<string> */
    public const HYGIENE_TABS = ['overview', 'releases', 'logs', 'notifications'];

    /** In-page tab: overview | releases | logs | notifications. */
    #[Url(as: 'tab', except: 'overview', history: true)]
    public string $hygiene_tab = 'overview';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public function setHygieneTab(string $tab): void
    {
        $this->hygiene_tab = in_array($tab, self::HYGIENE_TABS, true) ? $tab : 'overview';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->hygiene_tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    public function mount(Server $server): void
    {
        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

        if (! Feature::active('workspace.release_hygiene')) {
            if (workspace_release_hygiene_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->comingSoonPreview = false;
        $this->bootWorkspace($server);
        $this->mountReleaseHygieneLogViewer();
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

    public function render(ServerReleaseHygiene $hygiene): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-release-hygiene-preview');
        }

        $this->server->refresh();

        $onNotificationsTab = $this->hygiene_tab === 'notifications';

        return view('livewire.servers.workspace-release-hygiene', [
            'report' => $hygiene->forServer($this->server),
            'formatBytes' => fn (int $bytes): string => $hygiene->formatBytes($bytes),
            'notifChannels' => $onNotificationsTab ? $this->assignableReleaseHygieneNotificationChannels() : collect(),
            'notifSubscriptions' => $onNotificationsTab ? $this->releaseHygieneNotificationSubscriptions() : collect(),
            'notifEventLabels' => $onNotificationsTab ? $this->releaseHygieneEventLabels() : [],
        ]);
    }
}
