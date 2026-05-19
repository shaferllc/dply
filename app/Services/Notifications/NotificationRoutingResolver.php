<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationInboxItem;
use App\Models\NotificationSubscription;
use App\Models\User;
use App\Notifications\UniversalEventNotification;

class NotificationRoutingResolver
{
    public function __construct(
        private readonly NotificationWebhookDestinationRouter $webhookDestinationRouter,
    ) {}

    /**
     * @param  list<string>  $recipientUserIds
     * @param  list<string>  $excludeChannelIds  NotificationChannel ULIDs that have already received
     *                                           this event from a direct fan-out path and should be
     *                                           skipped here to avoid double-dispatch. Empty list
     *                                           preserves the original behaviour.
     */
    public function route(NotificationEvent $event, array $recipientUserIds = [], array $excludeChannelIds = []): void
    {
        if ($event->supports_in_app) {
            foreach (array_values(array_unique($recipientUserIds)) as $userId) {
                NotificationInboxItem::query()->create([
                    'notification_event_id' => $event->id,
                    'user_id' => $userId,
                    'resource_type' => $event->resource_type,
                    'resource_id' => $event->resource_id,
                    'title' => $event->title,
                    'body' => $event->body,
                    'url' => $event->url,
                    'metadata' => $event->metadata,
                ]);

                $user = User::query()->find($userId);
                if ($user instanceof User) {
                    $user->notify(new UniversalEventNotification($event));
                }
            }
        }

        if (! $event->supports_webhook || ! $event->resource_type || ! $event->resource_id) {
            return;
        }

        $excludeSet = array_flip($excludeChannelIds);

        $subs = NotificationSubscription::query()
            ->where('event_key', $event->event_key)
            ->where('subscribable_type', $event->resource_type)
            ->where('subscribable_id', $event->resource_id)
            ->with('channel')
            ->get()
            ->unique('notification_channel_id');

        foreach ($subs as $sub) {
            $channel = $sub->channel;
            if (! $channel instanceof NotificationChannel) {
                continue;
            }
            // Caller already dispatched to this channel directly (e.g. provision
            // failure fan-out hits every org channel always-on); skip the
            // subscription pipe so the operator doesn't see two copies.
            if (isset($excludeSet[(string) $channel->id])) {
                continue;
            }

            $channel->sendOperationalMessage(
                $event->title,
                $event->body ?? '',
                $event->url,
                $event->url ? __('Open in Dply') : null
            );
        }

        $this->webhookDestinationRouter->route($event);
    }
}
