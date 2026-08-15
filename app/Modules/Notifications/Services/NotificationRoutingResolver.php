<?php

namespace App\Modules\Notifications\Services;

use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationInboxItem;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UniversalEventNotification;

class NotificationRoutingResolver
{
    /**
     * @param  array<string, mixed>  $recipientUserIds
     * @param  array<string, mixed>  $excludeChannelIds  NotificationChannel ULIDs that have already received
     *                                                   this event from a direct fan-out path and should be
     *                                                   skipped here to avoid double-dispatch. Empty list
     *                                                   preserves the original behaviour.
     * @param  array<string, mixed>  $excludeRecipientUserIds  User ULIDs that have already received this event
     *                                                         in-app from a sibling publish and should be skipped
     *                                                         here so the inbox isn't double-filled. Empty list
     *                                                         preserves the original behaviour.
     */
    public function route(NotificationEvent $event, array $recipientUserIds = [], array $excludeChannelIds = [], array $excludeRecipientUserIds = []): void
    {
        if ($event->supports_in_app) {
            $excludeUserSet = array_flip($excludeRecipientUserIds);
            foreach (array_values(array_unique($recipientUserIds)) as $userId) {
                if (isset($excludeUserSet[$userId])) {
                    continue;
                }
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

        // Two shapes of subscription reach the same channel set:
        //  - per-resource: this exact server/site
        //  - org-wide: every resource in the organization, including ones created
        //    after the subscription was made. This is what
        //    NotificationWebhookDestination's "All sites in this org" scope did,
        //    and it is why that parallel system existed at all — subscriptions
        //    could previously only name one resource.
        $subs = NotificationSubscription::query()
            ->where('event_key', $event->event_key)
            ->where(function ($query) use ($event): void {
                $query->where(function ($q) use ($event): void {
                    $q->where('subscribable_type', $event->resource_type)
                        ->where('subscribable_id', $event->resource_id);
                });

                if ($event->organization_id !== null) {
                    $query->orWhere(function ($q) use ($event): void {
                        $q->where('subscribable_type', Organization::class)
                            ->where('subscribable_id', $event->organization_id);
                    });
                }
            })
            ->with('channel')
            ->get()
            // unique() matters more now: a channel subscribed both to this site
            // and org-wide must still only be messaged once.
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
                $event->url ? __('Open in Dply') : null,
                $this->alertContextFor($event),
            );
        }
    }

    /**
     * Metadata that incident-shaped channels need and chat-shaped ones ignore.
     *
     * The dedup key is deliberately (resource, event_key) and NOT the event id:
     * the whole point is that the twentieth "disk almost full" on the same
     * server updates one PagerDuty incident rather than opening a twentieth.
     * Including the event id would defeat that.
     *
     * @return array<string, mixed>
     */
    private function alertContextFor(NotificationEvent $event): array
    {
        return [
            'severity' => $event->severity,
            'event_key' => $event->event_key,
            'dedup_key' => implode(':', [
                'dply',
                (string) $event->resource_type,
                (string) $event->resource_id,
                (string) $event->event_key,
            ]),
            'source' => $event->resource_type && $event->resource_id
                ? class_basename((string) $event->resource_type).' '.$event->resource_id
                : (string) config('app.name'),
        ];
    }
}
