<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use App\Support\ServerSystemUserNotificationKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Powers the "Notifications" tab on the system-users workspace: a focused
 * subscription manager that binds notification channels to this server's
 * system_user.* events (created / updated / removed) without leaving the page.
 *
 * Deliberately scoped to system-user keys only — the generic server-settings
 * Alerts tab ({@see ManagesExtendedServerSettings}) whitelists a different set
 * and would reject these keys. Stakeholders already get in-app notifications
 * automatically; this tab is how an operator routes them to email/Slack/webhook.
 */
trait ManagesSystemUserNotifications
{
    /** Channel selected in the add-subscription form on the Notifications tab. */
    public string $notif_channel_id = '';

    /**
     * system_user event keys ticked in the add form. Seeded to all three so the
     * common case ("notify me about everything") is one click.
     *
     * @var list<string>
     */
    public array $notif_event_keys = [];

    public function mountManagesSystemUserNotifications(): void
    {
        $this->notif_event_keys = ServerSystemUserNotificationKeys::eventKeys();
    }

    public function addSystemUserNotificationSubscription(): void
    {
        $this->authorize('update', $this->server);

        $allowedKeys = ServerSystemUserNotificationKeys::eventKeys();

        $this->validate([
            'notif_channel_id' => ['required', 'string', 'exists:notification_channels,id'],
            'notif_event_keys' => ['required', 'array', 'min:1'],
            'notif_event_keys.*' => ['string', 'in:'.implode(',', $allowedKeys)],
        ], [], [
            'notif_channel_id' => __('channel'),
            'notif_event_keys' => __('notification types'),
        ]);

        $assignable = $this->assignableSystemUserNotificationChannels()
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
                    'scope' => 'system_user',
                ],
            );
        }

        $this->notif_channel_id = '';
        $this->notif_event_keys = ServerSystemUserNotificationKeys::eventKeys();

        $this->toastSuccess($created > 0
            ? __('Routing :count system-user event(s) to :channel.', ['count' => $created, 'channel' => $channel->label])
            : __('Those events are already routed to :channel.', ['channel' => $channel->label]));
    }

    public function removeSystemUserNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->server);

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', ServerSystemUserNotificationKeys::eventKeys())
            ->whereKey($subscriptionId)
            ->first();
        if ($sub === null) {
            return;
        }

        // Mirror the settings tab: only let someone detach a channel they can manage,
        // so an org member can't remove a team-owned channel's routing.
        $channel = $sub->channel;
        if ($channel !== null) {
            Gate::authorize('manageNotificationChannels', $channel->owner);
        }

        $snapshot = [
            'channel_id' => (string) $sub->notification_channel_id,
            'channel_label' => $channel?->label,
            'event_key' => $sub->event_key,
            'scope' => 'system_user',
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
    protected function assignableSystemUserNotificationChannels(): Collection
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()?->currentOrganization());
    }

    /**
     * @return Collection<int, NotificationSubscription>
     */
    protected function systemUserNotificationSubscriptions(): Collection
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', ServerSystemUserNotificationKeys::eventKeys())
            ->with('channel')
            ->get();
    }

    /**
     * Event key => human label, sourced from config so it stays in sync with the
     * registry and the global bulk-assign UI.
     *
     * @return array<string, string>
     */
    protected function systemUserEventLabels(): array
    {
        $events = (array) config('notification_events.categories.system_user.events', []);

        return array_map(static fn ($label) => (string) $label, $events);
    }
}
