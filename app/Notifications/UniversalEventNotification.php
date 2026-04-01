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
    ) {}

    /**
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
