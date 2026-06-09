<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\SurfacesErrorStream;
use App\Livewire\Sites\Concerns\ManagesErrorsNotifications;
use App\Models\ErrorEvent;
use App\Models\Server;
use App\Models\Site;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The site's "Errors" view — a chronological stream of this site's failures
 * (deploys, SSL, bindings, env, …). Stream behaviour lives in
 * {@see SurfacesErrorStream}; this is the per-site scope + the settings shell.
 * The Notifications sub-tab (routing channels to this site's site.errors.* events)
 * lives in {@see ManagesErrorsNotifications}.
 */
#[Layout('layouts.app')]
class Errors extends Component
{
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use DispatchesToastNotifications;
    use ManagesErrorsNotifications;
    use SurfacesErrorStream;
    use WithPagination;

    /** @var list<string> */
    public const ERRORS_TABS = ['stream', 'notifications'];

    public Server $server;

    public Site $site;

    #[Url(as: 'tab', except: 'stream')]
    public string $errorsTab = 'stream';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);
        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
    }

    public function setErrorsWorkspaceTab(string $tab): void
    {
        $this->errorsTab = in_array($tab, self::ERRORS_TABS, true) ? $tab : 'stream';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->errorsTab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    protected function scopedErrors(): Builder
    {
        return ErrorEvent::query()->forSite((string) $this->site->id);
    }

    /**
     * The unfiltered stream total equals the site's undismissed error count —
     * prime the shared memo so the settings sidebar "Errors" badge reuses it
     * instead of running the same count() again.
     */
    protected function shareStreamTotal(int $total): void
    {
        ErrorEvent::primeUndismissedCountForSite((string) $this->site->id, $total);
    }

    protected function authorizeErrorAccess(): void
    {
        Gate::authorize('update', $this->site);
    }

    public function render(): View
    {
        $runtimeMode = $this->site->runtimeTargetMode();
        $onNotificationsTab = $this->errorsTab === 'notifications';

        return view('livewire.sites.errors', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'errors',
            'runtimeMode' => $runtimeMode,
            'notifChannels' => $onNotificationsTab ? $this->assignableErrorsNotificationChannels() : collect(),
            'notifSubscriptions' => $onNotificationsTab ? $this->errorsNotificationSubscriptions() : collect(),
            'notifEventLabels' => $onNotificationsTab ? $this->errorsEventLabels() : [],
        ]);
    }
}
