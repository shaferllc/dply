<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Notifications\ServerDatabaseNotificationDispatcher;
use App\Support\ServerDatabaseNotificationKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Powers the "Notifications" tab on the databases workspace: binds notification
 * channels to this server's server.database.* events without leaving the page, and
 * provides the {@see dispatchDatabaseNotification()} helper for the engine/user
 * mutations. Database create/remove keep using the existing
 * {@see ServerDatabaseNotificationDispatcher::notifyIfSubscribed()} call sites.
 *
 * Mirrors {@see ManagesFirewallNotifications}, reusing the host's `$workspace_tab`.
 */
trait ManagesDatabaseNotifications
{
    /** Channel selected in the add-subscription form on the Notifications tab. */
    public string $notif_channel_id = '';

    /**
     * server.database.* event keys ticked in the add form. Seeded to all of them
     * so the common case ("notify me about everything") is one click.
     *
     * @var list<string>
     */
    public array $notif_event_keys = [];

    public function mountManagesDatabaseNotifications(): void
    {
        $this->notif_event_keys = ServerDatabaseNotificationKeys::eventKeys();
    }

    /**
     * Fire a database notification for this server at a mutation success point
     * (engine install/remove, user create/remove). The acting user is the operator.
     *
     * @param  list<string>  $detailLines
     * @param  array<string, mixed>  $extraMetadata
     */
    protected function dispatchDatabaseNotification(string $kind, array $detailLines = [], array $extraMetadata = []): void
    {
        app(ServerDatabaseNotificationDispatcher::class)->notify(
            $this->server->fresh(),
            $kind,
            $detailLines,
            Auth::user(),
            $extraMetadata,
        );
    }

    public function addDatabaseNotificationSubscription(): void
    {
        $this->authorize('update', $this->server);

        $allowedKeys = ServerDatabaseNotificationKeys::eventKeys();

        $this->validate([
            'notif_channel_id' => ['required', 'string', 'exists:notification_channels,id'],
            'notif_event_keys' => ['required', 'array', 'min:1'],
            'notif_event_keys.*' => ['string', 'in:'.implode(',', $allowedKeys)],
        ], [], [
            'notif_channel_id' => __('channel'),
            'notif_event_keys' => __('notification types'),
        ]);

        $assignable = $this->assignableDatabaseNotificationChannels()
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
                    'scope' => 'server_database',
                ],
            );
        }

        $this->notif_channel_id = '';
        $this->notif_event_keys = ServerDatabaseNotificationKeys::eventKeys();

        $this->toastSuccess($created > 0
            ? __('Routing :count database event(s) to :channel.', ['count' => $created, 'channel' => $channel->label])
            : __('Those events are already routed to :channel.', ['channel' => $channel->label]));
    }

    public function removeDatabaseNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->server);

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', ServerDatabaseNotificationKeys::eventKeys())
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
            'scope' => 'server_database',
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
    protected function assignableDatabaseNotificationChannels(): Collection
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()?->currentOrganization());
    }

    /**
     * @return Collection<int, NotificationSubscription>
     */
    protected function databaseNotificationSubscriptions(): Collection
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', ServerDatabaseNotificationKeys::eventKeys())
            ->with('channel')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function databaseEventLabels(): array
    {
        $events = (array) config('notification_events.categories.server_database.events', []);

        return array_map(static fn ($label) => (string) $label, $events);
    }
}
