<?php

namespace App\Livewire\Settings;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Support\NotificationSubscriptionRules;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class BulkNotificationAssignments extends Component
{
    /** @var list<int|string> */
    public array $selected_channel_ids = [];

    /** @var list<string> */
    public array $selected_event_keys = [];

    /** @var list<int|string> */
    public array $selected_server_ids = [];

    /** @var list<int|string> */
    public array $selected_site_ids = [];

    public ?string $flash_success = null;

    /**
     * @return Collection<int, NotificationChannel>
     */
    protected function channelsForUser()
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()->currentOrganization());
    }

    /**
     * @return Collection<int, Server>
     */
    protected function serversForCurrentOrg(?Organization $org)
    {
        if (! $org) {
            return collect();
        }

        return Server::query()
            ->where('organization_id', $org->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Server $s) => Gate::allows('view', $s));
    }

    /**
     * @return Collection<int, Site>
     */
    protected function sitesForCurrentOrg(?Organization $org)
    {
        if (! $org) {
            return collect();
        }

        return Site::query()
            ->where('organization_id', $org->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $s) => Gate::allows('view', $s));
    }

    public function selectAllChannels(): void
    {
        $this->selected_channel_ids = $this->channelsForUser()->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function deselectAllChannels(): void
    {
        $this->selected_channel_ids = [];
    }

    public function selectAllEvents(): void
    {
        $keys = [];
        foreach (config('notification_events.categories', []) as $cat) {
            foreach ($cat['events'] as $k => $_) {
                $keys[] = $k;
            }
        }
        $this->selected_event_keys = $keys;
    }

    public function deselectAllEvents(): void
    {
        $this->selected_event_keys = [];
    }

    public function selectAllServers(): void
    {
        $org = Auth::user()->currentOrganization();
        $this->selected_server_ids = $this->serversForCurrentOrg($org)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function deselectAllServers(): void
    {
        $this->selected_server_ids = [];
    }

    public function selectAllSites(): void
    {
        $org = Auth::user()->currentOrganization();
        $this->selected_site_ids = $this->sitesForCurrentOrg($org)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function deselectAllSites(): void
    {
        $this->selected_site_ids = [];
    }

    public function canSubmitAssign(): bool
    {
        if ($this->selected_channel_ids === [] || $this->selected_event_keys === []) {
            return false;
        }

        if (Auth::user()->currentOrganization() === null) {
            return false;
        }

        $needsServers = false;
        $needsSites = false;
        foreach ($this->selected_event_keys as $event) {
            $class = NotificationSubscriptionRules::subscribableClassForEvent($event);
            if ($class === Server::class) {
                $needsServers = true;
            }
            if ($class === Site::class) {
                $needsSites = true;
            }
        }

        if ($needsServers && $this->selected_server_ids === []) {
            return false;
        }
        if ($needsSites && $this->selected_site_ids === []) {
            return false;
        }

        return true;
    }

    public function assign(): void
    {
        $this->resetErrorBag();
        $this->validate([
            'selected_channel_ids' => ['required', 'array', 'min:1'],
            'selected_channel_ids.*' => ['string', 'exists:notification_channels,id'],
            'selected_event_keys' => ['required', 'array', 'min:1'],
            'selected_event_keys.*' => ['string', 'max:80'],
            'selected_server_ids' => ['array'],
            'selected_server_ids.*' => ['string', 'exists:servers,id'],
            'selected_site_ids' => ['array'],
            'selected_site_ids.*' => ['string', 'exists:sites,id'],
        ], [], [
            'selected_channel_ids' => __('channels'),
            'selected_event_keys' => __('notification types'),
        ]);

        $allowedIds = $this->channelsForUser()->pluck('id')->all();
        foreach ($this->selected_channel_ids as $cid) {
            if (! in_array((string) $cid, $allowedIds, true)) {
                $this->addError('selected_channel_ids', __('Invalid channel selected.'));

                return;
            }
        }

        $validEvents = [];
        foreach (config('notification_events.categories', []) as $cat) {
            foreach ($cat['events'] as $k => $_) {
                $validEvents[] = $k;
            }
        }
        foreach ($this->selected_event_keys as $ek) {
            if (! in_array($ek, $validEvents, true)) {
                $this->addError('selected_event_keys', __('Invalid notification type.'));

                return;
            }
        }

        $needsServers = false;
        $needsSites = false;
        foreach ($this->selected_event_keys as $event) {
            $class = NotificationSubscriptionRules::subscribableClassForEvent($event);
            if ($class === Server::class) {
                $needsServers = true;
            }
            if ($class === Site::class) {
                $needsSites = true;
            }
        }

        if ($needsServers && $this->selected_server_ids === []) {
            $this->addError('selected_server_ids', __('Select at least one server for the chosen notification types.'));

            return;
        }
        if ($needsSites && $this->selected_site_ids === []) {
            $this->addError('selected_site_ids', __('Select at least one site for the chosen notification types.'));

            return;
        }

        $org = Auth::user()->currentOrganization();
        if (! $org) {
            $this->addError('selected_channel_ids', __('Choose a current organization (switch org in the header) to assign server or site targets.'));

            return;
        }

        $created = 0;

        DB::transaction(function () use (&$created, $org): void {
            foreach ($this->selected_channel_ids as $cid) {
                $channel = NotificationChannel::query()->findOrFail((string) $cid);
                Gate::authorize('manageNotificationChannels', $channel->owner);

                foreach ($this->selected_event_keys as $event) {
                    $class = NotificationSubscriptionRules::subscribableClassForEvent($event);
                    if ($class === Server::class) {
                        foreach ($this->selected_server_ids as $sid) {
                            $server = Server::query()->where('organization_id', $org->id)->findOrFail((string) $sid);
                            Gate::authorize('view', $server);
                            $row = NotificationSubscription::firstOrCreate([
                                'notification_channel_id' => $channel->id,
                                'subscribable_type' => Server::class,
                                'subscribable_id' => $server->id,
                                'event_key' => $event,
                            ]);
                            if ($row->wasRecentlyCreated) {
                                $created++;
                            }
                        }
                    } elseif ($class === Site::class) {
                        foreach ($this->selected_site_ids as $siteId) {
                            $site = Site::query()->where('organization_id', $org->id)->findOrFail((string) $siteId);
                            Gate::authorize('view', $site);
                            $row = NotificationSubscription::firstOrCreate([
                                'notification_channel_id' => $channel->id,
                                'subscribable_type' => Site::class,
                                'subscribable_id' => $site->id,
                                'event_key' => $event,
                            ]);
                            if ($row->wasRecentlyCreated) {
                                $created++;
                            }
                        }
                    }
                }
            }
        });

        $this->flash_success = __('Assignments saved. :count new subscription(s) added.', ['count' => $created]);
    }

    public function render(): View
    {
        $org = Auth::user()->currentOrganization();

        return view('livewire.settings.bulk-notification-assignments', [
            'assignableChannels' => $this->channelsForUser(),
            'eventCatalog' => config('notification_events.categories', []),
            'servers' => $this->serversForCurrentOrg($org),
            'sites' => $this->sitesForCurrentOrg($org),
            'currentOrganization' => $org,
        ]);
    }
}
