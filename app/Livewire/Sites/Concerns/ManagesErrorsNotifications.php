<?php

namespace App\Livewire\Sites\Concerns;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Site;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Support\Errors\ErrorEventSyncer;
use App\Support\SiteErrorsNotificationKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Powers the "Notifications" sub-tab on the site errors workspace: binds
 * notification channels to this site's site.errors.* events without leaving the
 * page. The site mirror of {@see \App\Livewire\Servers\Concerns\ManagesErrorsNotifications}.
 *
 * Error events fire per newly-captured row from {@see ErrorEventSyncer}
 * (the per-minute sweep), so this trait is subscription management only. The same
 * failure also surfaces in the owning server's roll-up; the dispatcher dedupes the
 * two so subscribing here doesn't double up with a server.errors.* subscription.
 */
trait ManagesErrorsNotifications
{
    /** Channel selected in the add-subscription form on the Notifications tab. */
    public string $notif_channel_id = '';

    /**
     * site.errors.* event keys ticked in the add form. Seeded to all of them so
     * the common case ("notify me about everything") is one click.
     *
     * @var list<string>
     */
    public array $notif_event_keys = [];

    public function mountManagesErrorsNotifications(): void
    {
        $this->notif_event_keys = SiteErrorsNotificationKeys::eventKeys();
    }

    public function addErrorsNotificationSubscription(): void
    {
        $this->authorize('update', $this->site);

        $allowedKeys = SiteErrorsNotificationKeys::eventKeys();

        $this->validate([
            'notif_channel_id' => ['required', 'string', 'exists:notification_channels,id'],
            'notif_event_keys' => ['required', 'array', 'min:1'],
            'notif_event_keys.*' => ['string', 'in:'.implode(',', $allowedKeys)],
        ], [], [
            'notif_channel_id' => __('channel'),
            'notif_event_keys' => __('notification types'),
        ]);

        $assignable = $this->assignableErrorsNotificationChannels()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (! in_array($this->notif_channel_id, $assignable, true)) {
            $this->addError('notif_channel_id', __('Channel is not assignable to this site.'));

            return;
        }

        $channel = NotificationChannel::query()->findOrFail($this->notif_channel_id);
        Gate::authorize('manageNotificationChannels', $channel->owner);

        $created = 0;
        $createdKeys = [];
        foreach ($this->notif_event_keys as $eventKey) {
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
                    'scope' => 'errors',
                ],
            );
        }

        $this->notif_channel_id = '';
        $this->notif_event_keys = SiteErrorsNotificationKeys::eventKeys();

        $this->toastSuccess($created > 0
            ? __('Routing :count error event(s) to :channel.', ['count' => $created, 'channel' => $channel->label])
            : __('Those events are already routed to :channel.', ['channel' => $channel->label]));
    }

    public function removeErrorsNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->site);

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Site::class)
            ->where('subscribable_id', $this->site->id)
            ->whereIn('event_key', SiteErrorsNotificationKeys::eventKeys())
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
            'scope' => 'errors',
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
    protected function assignableErrorsNotificationChannels(): Collection
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()?->currentOrganization());
    }

    /**
     * @return Collection<int, NotificationSubscription>
     */
    protected function errorsNotificationSubscriptions(): Collection
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Site::class)
            ->where('subscribable_id', $this->site->id)
            ->whereIn('event_key', SiteErrorsNotificationKeys::eventKeys())
            ->with('channel')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function errorsEventLabels(): array
    {
        $events = (array) config('notification_events.categories.site_errors.events', []);

        return array_map(static fn ($label) => (string) $label, $events);
    }
}
