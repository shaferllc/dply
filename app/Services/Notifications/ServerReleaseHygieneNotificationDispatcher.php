<?php

namespace App\Services\Notifications;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerReleaseHygieneScanner;
use App\Support\ServerReleaseHygieneNotificationKeys;

/**
 * Publishes notifications for the server release hygiene workspace — pressure
 * transitions detected after a scan ({@see ServerReleaseHygieneScanner}):
 * a new critical / warning finding (disk, release folders, log sizes, failed jobs),
 * or a recovery to a healthy posture.
 *
 * Mirrors {@see ServerSecurityDigestNotificationDispatcher}. Subject is the
 * {@see Server} the hygiene report belongs to; the per-kind title is the config label.
 */
final class ServerReleaseHygieneNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed> $detailLines
     * @param  array<string, mixed> $extraMetadata
     */
    public function notify(
        Server $server,
        string $kind,
        array $detailLines = [],
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerReleaseHygieneNotificationKeys::KINDS, true)) {
            return;
        }

        $detailLines = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $detailLines,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerReleaseHygieneNotificationKeys::eventKey($kind);
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
            url: route('servers.hygiene', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.release_hygiene.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
