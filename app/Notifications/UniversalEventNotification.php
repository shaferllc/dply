<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UniversalEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public NotificationEvent $event
    ) {
        // Stay off the heavy deploy/Edge queues so a build backlog never
        // delays in-app notifications (Horizon supervisor-fast).
        $this->onQueue((string) config('dply.notification_queue', 'default'));
    }

    /**
     * Deliberately has NO `intercom` leg, unlike every other notification in
     * this namespace — do not add the DeliversToIntercom trait here. (Named in
     * prose rather than a {@see} tag on purpose: a tag makes Pint import the
     * trait, and an import in this file reads like the opposite of this note.)
     *
     * This class is the in-app/broadcast twin of a NotificationEvent that
     * NotificationRoutingResolver has *already* fanned out to every subscribed
     * NotificationChannel via sendOperationalMessage() — Intercom channels
     * included. An Intercom leg here would deliver the same event twice to
     * anyone holding both an inbox entry and an Intercom subscription.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('broadcasting.default') !== 'null') {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'notification_event_id' => $this->event->id,
            'event_key' => $this->event->event_key,
            'title' => $this->event->title,
            'body' => $this->event->body,
            'url' => $this->event->url,
            'severity' => $this->event->severity,
            'category' => $this->event->category,
            'resource_type' => $this->event->resource_type,
            'resource_id' => $this->event->resource_id,
            'metadata' => $this->event->metadata ?? [],
            'occurred_at' => $this->event->occurred_at?->toIso8601String(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'universal.notification';
    }
}
