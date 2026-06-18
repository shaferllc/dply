<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Modules\Notifications\Services\AssignableNotificationChannels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesMonitorNotifications
{


    /**
     * After creating a channel inline, auto-select it in the subscription form.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->notifAddChannelId = $channelId;
    }

    /**
     * Add notification subscription(s) for this server.
     */
    public function addServerNotificationSubscription(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change notification subscriptions.'));

            return;
        }

        $this->validate([
            'notifAddChannelId' => ['required', 'string', 'exists:notification_channels,id'],
            'notifAddEventKeys' => ['required', 'array', 'min:1'],
            'notifAddEventKeys.*' => ['string', 'in:server.automatic_updates,server.ssh_login,server.insights_alerts,server.monitoring,server.shared_host_alerts'],
        ], [], [
            'notifAddChannelId' => __('channel'),
            'notifAddEventKeys' => __('notification types'),
        ]);

        $org = Auth::user()?->currentOrganization();
        $allowed = AssignableNotificationChannels::forUser(Auth::user(), $org)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (! in_array($this->notifAddChannelId, $allowed, true)) {
            $this->addError('notifAddChannelId', __('Channel is not assignable to this server.'));

            return;
        }

        $channel = NotificationChannel::query()->findOrFail($this->notifAddChannelId);
        Gate::authorize('manageNotificationChannels', $channel->owner);

        $created = 0;
        foreach ($this->notifAddEventKeys as $eventKey) {
            $row = NotificationSubscription::firstOrCreate([
                'notification_channel_id' => $channel->id,
                'subscribable_type' => Server::class,
                'subscribable_id' => $this->server->id,
                'event_key' => $eventKey,
            ]);
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->notifAddChannelId = '';
        $this->notifAddEventKeys = [];
        $this->toastSuccess(__('Added :count subscription(s) routing this server\'s events to :channel.', [
            'count' => $created,
            'channel' => $channel->label,
        ]));
    }

    /**
     * Remove a notification subscription from this server.
     */
    public function removeServerNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change notification subscriptions.'));

            return;
        }

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereKey($subscriptionId)
            ->first();

        if ($sub === null) {
            return;
        }

        // Only allow removal when the user can manage the underlying channel
        $channel = $sub->channel;
        if ($channel instanceof NotificationChannel) {
            Gate::authorize('manageNotificationChannels', $channel->owner);
        }

        $sub->delete();
        $this->toastSuccess(__('Subscription removed.'));
    }
}
