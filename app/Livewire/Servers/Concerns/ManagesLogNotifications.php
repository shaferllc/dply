<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Support\ServerLogNotificationKeys;
use Illuminate\Database\Eloquent\Collection;

/**
 * Powers the "Notifications" tab on server and site Logs: binds notification
 * channels to this server's `server.logs.alert_triggered` event without leaving
 * the page.
 *
 * Threshold / pattern rules stay on the paid Alerts tab. Stakeholders already
 * get in-app notifications automatically; this tab routes them to email /
 * Slack / webhook. Mirrors {@see ManagesHealthNotifications} (matrix only —
 * no leftover add-form).
 */
trait ManagesLogNotifications
{
    use ManagesFeatureNotificationMatrix;

    /**
     * @return list<string>
     */
    protected function featureEventKeys(): array
    {
        return ServerLogNotificationKeys::eventKeys();
    }

    public function mountManagesLogNotifications(): void
    {
        $this->bootFeatureNotificationMatrix();
    }

    /**
     * @return Collection<int, NotificationSubscription>
     */
    protected function logNotificationSubscriptions(): Collection
    {
        return NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereIn('event_key', ServerLogNotificationKeys::eventKeys())
            ->with('channel')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    protected function logEventLabels(): array
    {
        $events = (array) config('notification_events.categories.logs.events', []);

        return array_map(static fn ($label) => (string) $label, $events);
    }

    protected function featureNotificationSurface(): string
    {
        return 'logs';
    }
}
