<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\LookupSiteErrorReferenceJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\SurfacesErrorStream;
use App\Livewire\Sites\Concerns\ManagesErrorsNotifications;
use App\Models\ConsoleAction;
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

    /** The reference code (X-Dply-Ref) the operator pasted from a 5xx page. */
    public string $referenceQuery = '';

    /** ConsoleAction id of the in-flight / last reference lookup, if any. */
    public ?string $lookupRunId = null;

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
     * Resolve a reference code from a branded 5xx page back to the actual request
     * + error trace. SSH must not run inline (PHP 30s wall) so this dispatches a
     * queued job that streams its result into a ConsoleAction this view polls.
     */
    public function lookupReference(): void
    {
        $this->authorizeErrorAccess();

        $reference = trim($this->referenceQuery);
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $reference)) {
            $this->toastError(__('Enter the reference code shown on the error page.'));

            return;
        }

        if (! $this->referenceLookupAvailable()) {
            $this->toastError(__('Reference lookup needs an SSH-managed server.'));

            return;
        }

        // Supersede any prior lookup banner for this site so the new run is the
        // one on screen.
        ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->where('kind', 'error_reference_lookup')
            ->whereNull('dismissed_at')
            ->update(['dismissed_at' => now()]);

        $run = ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => 'error_reference_lookup',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => __('Resolving reference :ref', ['ref' => $reference]),
            'user_id' => auth()->id(),
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        LookupSiteErrorReferenceJob::dispatch(
            (string) $this->site->id,
            $reference,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->lookupRunId = (string) $run->id;
        $this->dispatch('notify', message: __('Looking up :ref…', ['ref' => $reference]));
    }

    /** The in-flight / last reference-lookup ConsoleAction, for the result panel. */
    public function lookupRun(): ?ConsoleAction
    {
        if ($this->lookupRunId === null) {
            return null;
        }

        return ConsoleAction::query()->find($this->lookupRunId);
    }

    protected function referenceLookupAvailable(): bool
    {
        return (bool) $this->site->server?->hostCapabilities()->supportsSsh();
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
            'referenceLookupAvailable' => $this->referenceLookupAvailable(),
            'lookupRun' => $this->lookupRun(),
        ]);
    }
}
