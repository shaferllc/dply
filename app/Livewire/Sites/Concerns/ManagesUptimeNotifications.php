<?php

namespace App\Livewire\Sites\Concerns;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Site;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Support\SiteUptimeNotificationKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Powers the "Alerts" card on the site monitor page: binds notification channels
 * to this site's site.uptime.* / site.ssl.expiring events without leaving the
 * page. The uptime mirror of {@see ManagesErrorsNotifications}.
 *
 * Uptime events fire from {@see \App\Jobs\RunSiteUptimeMonitorCheckJob} at the
 * state edge (down/recovered/degraded/ssl-expiring); in-app delivery to org
 * members happens regardless of any subscription, so this card only governs the
 * external channels (email / Slack / webhook…).
 */
trait ManagesUptimeNotifications
{
    /** Channel selected in the add-subscription form on the Alerts card. */
    public string $uptime_notif_channel_id = '';

    /**
     * site.uptime.* / site.ssl.expiring event keys ticked in the add form.
     * Seeded to all of them so "notify me about everything" is one click.
     *
     * @var list<string>
     */
    public array $uptime_notif_event_keys = [];

    public function mountManagesUptimeNotifications(): void
    {
        $this->uptime_notif_event_keys = SiteUptimeNotificationKeys::eventKeys();
    }

    public function addUptimeNotificationSubscription(): void
    {
        $this->authorize('update', $this->site);

        $allowedKeys = SiteUptimeNotificationKeys::eventKeys();

        $this->validate([
            'uptime_notif_channel_id' => ['required', 'string', 'exists:notification_channels,id'],
            'uptime_notif_event_keys' => ['required', 'array', 'min:1'],
            'uptime_notif_event_keys.*' => ['string', 'in:'.implode(',', $allowedKeys)],
        ], [], [
            'uptime_notif_channel_id' => __('channel'),
            'uptime_notif_event_keys' => __('notification types'),
        ]);

        $assignable = $this->assignableUptimeNotificationChannels()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (! in_array($this->uptime_notif_channel_id, $assignable, true)) {
            $this->addError('uptime_notif_channel_id', __('Channel is not assignable to this site.'));

            return;
        }

        $channel = NotificationChannel::query()->findOrFail($this->uptime_notif_channel_id);
        Gate::authorize('manageNotificationChannels', $channel->owner);

        $created = 0;
        $createdKeys = [];
        foreach ($this->uptime_notif_event_keys as $eventKey) {
            $row = NotificationSubscription::firstOrCreate([
                'notification_channel_id' => $channel->id,
                'subscribable_type' => Site::class,
                'subscribable_id' => $this->site->id,
                'event_key' => $eventKey,
            ]);
            if ($row->wasRecentlyCreated) {
                $created++;
                $createdKeys[] = $eventKey;
            }
        }

        if ($created > 0 && $this->site->organization) {
            audit_log(
                $this->site->organization,
                Auth::user(),
                'site.notifications.subscription_added',
                $this->site,
                null,
                [
                    'channel_id' => (string) $channel->id,
                    'channel_label' => $channel->label,
                    'event_keys' => $createdKeys,
                    'count' => $created,
                    'scope' => 'uptime',
                ],
            );
        }

        $this->uptime_notif_channel_id = '';
        $this->uptime_notif_event_keys = SiteUptimeNotificationKeys::eventKeys();

        $this->toastSuccess($created > 0
            ? __('Routing :count uptime event(s) to :channel.', ['count' => $created, 'channel' => $channel->label])
            : __('Those events are already routed to :channel.', ['channel' => $channel->label]));
    }

    public function removeUptimeNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->site);

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Site::class)
            ->where('subscribable_id', $this->site->id)
            ->whereIn('event_key', SiteUptimeNotificationKeys::eventKeys())
            ->whereKey($subscriptionId)
            ->first();
        if ($sub === null) {
            return;
        }

        $channel = $sub->channel;
        if ($channel instanceof NotificationChannel) {
            Gate::authorize('manageNotificationChannels', $channel->owner);
        }

        $snapshot = [
            'channel_id' => (string) $sub->notification_channel_id,
            'channel_label' => $channel?->label,
            'event_key' => $sub->event_key,
            'scope' => 'uptime',
        ];
        $sub->delete();

        if ($this->site->organization) {
            audit_log(
                $this->site->organization,
                Auth::user(),
                'site.notifications.subscription_removed',
                $this->site,
                $snapshot,
                null,
            );
        }

        $this->toastSuccess(__('Subscription removed.'));
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    protected function assignableUptimeNotificationChannels(): Collection
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()?->currentOrganization());
    }

    /**
     * @return Collection<int, NotificationSubscription>
     */
    protected function uptimeNotificationSubscriptions(): Collection
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Site::class)
            ->where('subscribable_id', $this->site->id)
            ->whereIn('event_key', SiteUptimeNotificationKeys::eventKeys())
            ->with('channel')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function uptimeEventLabels(): array
    {
        $events = (array) config('notification_events.categories.site_uptime.events', []);

        return array_map(static fn ($label) => (string) $label, $events);
    }
}
