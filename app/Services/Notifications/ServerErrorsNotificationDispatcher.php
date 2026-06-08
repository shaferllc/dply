<?php

namespace App\Services\Notifications;

use App\Models\ErrorEvent;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerErrorsNotificationKeys;
use Illuminate\Support\Str;

/**
 * Publishes a notification for a single newly-captured {@see ErrorEvent} — fired
 * from {@see \App\Support\Errors\ErrorEventSyncer} as the per-minute sweep promotes
 * a failed ConsoleAction / SiteDeployment into the server's error stream.
 *
 * Subject is the {@see Server} the error belongs to (errors with no server_id —
 * pure org/site-level — can't route to a server subscribable and are skipped).
 * The per-kind title is the config label; the body carries the error's own title
 * and a clipped detail, and "Open" deep-links to the source where it lives.
 *
 * Mirrors {@see ServerHealthNotificationDispatcher}, but per-event rather than
 * per-posture-transition (errors are discrete, not a rollup).
 */
final class ServerErrorsNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    public function notify(ErrorEvent $event, ?User $actor = null): void
    {
        $server = $event->server;
        if (! $server instanceof Server) {
            return;
        }

        $kind = ServerErrorsNotificationKeys::kindForCategory((string) $event->category);
        $eventKey = ServerErrorsNotificationKeys::eventKey($kind);
        $label = $this->label($eventKey, $kind);

        $title = '['.config('app.name').'] '.$server->name.' — '.$label;

        $lines = [__('Server: :name', ['name' => $server->name])];

        $errorTitle = trim((string) $event->title);
        if ($errorTitle !== '') {
            $lines[] = $errorTitle;
        }

        $detail = trim((string) $event->detail);
        if ($detail !== '') {
            $lines[] = Str::limit($detail, 500);
        }

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            // Prefer the source's own deep link (the failed deploy / settings
            // section) so the alert lands where you can act; fall back to the
            // server's Errors stream.
            url: ($event->link_url ?: null) ?? route('servers.errors', $server, absolute: true),
            metadata: [
                'server_id' => $server->id,
                'error_event_id' => $event->id,
                'category' => (string) $event->category,
                'site_id' => $event->site_id,
                'kind' => $kind,
            ],
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.errors.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
