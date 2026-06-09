<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Notifications\ServerBackupNotificationDispatcher;
use App\Support\ServerBackupNotificationKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Powers the "Notifications" tab on the backups workspace: binds notification
 * channels to this server's server.backup.* events without leaving the page, and
 * provides the {@see dispatchBackupNotification()} helper the component calls at
 * each backup mutation success point.
 *
 * Scoped to server-backup keys only (distinct from the older site-scoped backup.*
 * keys). Stakeholders already get in-app notifications automatically; this tab
 * routes them to email/Slack/webhook. Mirrors {@see ManagesSnapshotsNotifications},
 * reusing the host's existing `$backups_workspace_tab` state.
 */
trait ManagesBackupNotifications
{
    /** Channel selected in the add-subscription form on the Notifications tab. */
    public string $notif_channel_id = '';

    /**
     * server.backup.* event keys ticked in the add form. Seeded to all of them so
     * the common case ("notify me about everything") is one click.
     *
     * @var list<string>
     */
    public array $notif_event_keys = [];

    public function mountManagesBackupNotifications(): void
    {
        $this->notif_event_keys = ServerBackupNotificationKeys::eventKeys();
    }

    /**
     * Fire a backup notification for this server at a mutation success point.
     * The acting user is the current operator.
     *
     * @param  list<string>  $resourceLabels
     * @param  array<string, mixed>  $extraMetadata  should include `backup_type`
     */
    protected function dispatchBackupNotification(string $kind, array $resourceLabels = [], array $extraMetadata = []): void
    {
        app(ServerBackupNotificationDispatcher::class)->notify(
            $this->server->fresh(),
            $kind,
            $resourceLabels,
            Auth::user(),
            $extraMetadata,
        );
    }

    public function addBackupNotificationSubscription(): void
    {
        $this->authorize('update', $this->server);

        $allowedKeys = ServerBackupNotificationKeys::eventKeys();

        $this->validate([
            'notif_channel_id' => ['required', 'string', 'exists:notification_channels,id'],
            'notif_event_keys' => ['required', 'array', 'min:1'],
            'notif_event_keys.*' => ['string', 'in:'.implode(',', $allowedKeys)],
        ], [], [
            'notif_channel_id' => __('channel'),
            'notif_event_keys' => __('notification types'),
        ]);

        $assignable = $this->assignableBackupNotificationChannels()
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
                    'scope' => 'server_backup',
                ],
            );
        }

        $this->notif_channel_id = '';
        $this->notif_event_keys = ServerBackupNotificationKeys::eventKeys();

        $this->toastSuccess($created > 0
            ? __('Routing :count backup event(s) to :channel.', ['count' => $created, 'channel' => $channel->label])
            : __('Those events are already routed to :channel.', ['channel' => $channel->label]));
    }

    public function removeBackupNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->server);

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', ServerBackupNotificationKeys::eventKeys())
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
            'scope' => 'server_backup',
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
    protected function assignableBackupNotificationChannels(): Collection
    {
        return AssignableNotificationChannels::forUser(Auth::user(), Auth::user()?->currentOrganization());
    }

    /**
     * @return Collection<int, NotificationSubscription>
     */
    protected function backupNotificationSubscriptions(): Collection
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', ServerBackupNotificationKeys::eventKeys())
            ->with('channel')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function backupEventLabels(): array
    {
        $events = (array) config('notification_events.categories.server_backup.events', []);

        return array_map(static fn ($label) => (string) $label, $events);
    }
}
