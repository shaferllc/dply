<?php

namespace App\Services\Notifications;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerHealthNotifier;
use App\Support\ServerHealthNotificationKeys;

/**
 * Publishes notifications for the server health cockpit — posture transitions
 * detected when the cockpit is evaluated ({@see ServerHealthNotifier}):
 * a new critical / warning alert, or a recovery to a healthy posture.
 *
 * Mirrors {@see ServerSecurityDigestNotificationDispatcher}. Subject is the
 * {@see Server} the cockpit belongs to; the per-kind title is the config label.
 */
final class ServerHealthNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  list<string>  $detailLines
     * @param  array<string, mixed>  $extraMetadata
     */
    public function notify(
        Server $server,
        string $kind,
        array $detailLines = [],
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerHealthNotificationKeys::KINDS, true)) {
            return;
        }

        $detailLines = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $detailLines,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerHealthNotificationKeys::eventKey($kind);
        $label = $this->label($eventKey, $kind);

        $title = '['.config('app.name').'] '.$server->name.' — '.$label;

        $lines = [__('Server: :name', ['name' => $server->name])];

        foreach ($detailLines as $line) {
            $lines[] = $line;
        }

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            url: route('servers.health', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.health.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
