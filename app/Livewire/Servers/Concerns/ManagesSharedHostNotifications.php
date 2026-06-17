<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\SharedHostNotificationDispatcher;
use App\Support\Servers\SharedHostBudgetMonitor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Powers the "Notifications" tab on the shared-host workspace: binds notification
 * channels to this server's `server.shared_host_alerts` event without leaving the
 * page.
 *
 * Shared host alerts (budget breaches + critical contention events) already fire
 * via {@see SharedHostNotificationDispatcher}, driven by the
 * post-scan {@see SharedHostBudgetMonitor} and the scheduled
 * EvaluateSharedHostBudgetsCommand. This trait only adds in-page subscription
 * management so operators can route those alerts to email/Slack/webhook without
 * detouring to Settings. Deliberately reuses the existing single event key.
 * Mirrors {@see ManagesInsightsNotifications}, scoped to one key.
 */
trait ManagesSharedHostNotifications
{
    /** Channel selected in the add-subscription form on the Notifications tab. */
    public string $notif_channel_id = '';

    /**
     * Shared host event keys ticked in the add form (just the one). Seeded so the
     * common case ("notify me") is one click.
     *
     * @var list<string>
     */
    public array $notif_event_keys = [];

    public function mountManagesSharedHostNotifications(): void
    {
        $this->notif_event_keys = $this->sharedHostEventKeys();
    }

    /**
     * @return list<string>
     */
    protected function sharedHostEventKeys(): array
    {
        return [SharedHostNotificationDispatcher::EVENT_KEY];
    }

    public function addSharedHostNotificationSubscription(): void
    {
        $this->authorize('update', $this->server);

        $allowedKeys = $this->sharedHostEventKeys();

        $this->validate([
            'notif_channel_id' => ['required', 'string', 'exists:notification_channels,id'],
            'notif_event_keys' => ['required', 'array', 'min:1'],
            'notif_event_keys.*' => ['string', 'in:'.implode(',', $allowedKeys)],
        ], [], [
            'notif_channel_id' => __('channel'),
            'notif_event_keys' => __('notification types'),
        ]);

        $assignable = $this->assignableSharedHostNotificationChannels()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (! in_array($this->notif_channel_id, $assignable, true)) {
            $this->addError('notif_channel_id', __('Channel is not assignable to this server.'));

            return;
        }

        $channel = NotificationChannel::query()->findOrFail($this->notif_channel_id);
        Gate::authorize('manageNotificationChannels', $channel->owner);

        $created = 0;
        $createdKeys = [];
        foreach ($this->notif_event_keys as $eventKey) {
            $row = NotificationSubscription::firstOrCreate([
                'notification_channel_id' => $channel->id,
                'subscribable_type' => Server::class,
                'subscribable_id' => $this->server->id,
                'event_key' => $eventKey,
            ]);
            if ($row->wasRecentlyCreated) {
                $created++;
                $createdKeys[] = $eventKey;
            }
        }

        if ($created > 0 && $this->server->organization) {
            audit_log(
                $this->server->organization,
                Auth::user(),
                'server.notifications.subscription_added',
                $this->server,
                null,
                [
                    'channel_id' => (string) $channel->id,
                    'channel_label' => $channel->label,
                    'event_keys' => $createdKeys,
                    'count' => $created,
                    'scope' => 'shared_host',
                ],
            );
        }

        $this->notif_channel_id = '';
        $this->notif_event_keys = $this->sharedHostEventKeys();

        $this->toastSuccess($created > 0
            ? __('Routing shared host alerts to :channel.', ['channel' => $channel->label])
            : __('Shared host alerts are already routed to :channel.', ['channel' => $channel->label]));
    }

    public function removeSharedHostNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->server);

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', $this->sharedHostEventKeys())
            ->whereKey($subscriptionId)
            ->first();
        if ($sub === null) {
            return;
        }

        $channel = $sub->channel;
        if ($channel !== null) {
            Gate::authorize('manageNotificationChannels', $channel->owner);
        }

        $snapshot = [
            'channel_id' => (string) $sub->notification_channel_id,
            'channel_label' => $channel?->label,
            'event_key' => $sub->event_key,
            'scope' => 'shared_host',
        ];
        $sub->delete();

        if ($this->server->organization) {
            audit_log(
                $this->server->organization,
                Auth::user(),
                'server.notifications.subscription_removed',
                $this->server,
                $snapshot,
                null,
            );
        }

        $this->toastSuccess(__('Subscription removed.'));
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    protected function assignableSharedHostNotificationChannels(): Collection
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()?->currentOrganization());
    }

    /**
     * @return Collection<int, NotificationSubscription>
     */
    protected function sharedHostNotificationSubscriptions(): Collection
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', $this->sharedHostEventKeys())
            ->with('channel')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function sharedHostEventLabels(): array
    {
        $serverEvents = (array) config('notification_events.categories.server.events', []);
        $labels = [];
        foreach ($this->sharedHostEventKeys() as $key) {
            $labels[$key] = (string) ($serverEvents[$key] ?? __('Shared host contention & budget alerts'));
        }

        return $labels;
    }
}
