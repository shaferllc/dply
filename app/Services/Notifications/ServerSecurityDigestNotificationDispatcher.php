<?php

namespace App\Services\Notifications;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerSecurityDigestScanner;
use App\Support\ServerSecurityDigestNotificationKeys;

/**
 * Publishes notifications for the server security digest — posture transitions
 * detected after a scan ({@see ServerSecurityDigestScanner}):
 * a new critical / warning finding, or a recovery to a healthy posture.
 *
 * Mirrors {@see ServerDeployPolicyNotificationDispatcher}. Subject is the
 * {@see Server} the digest belongs to; the per-kind title is the config label.
 */
final class ServerSecurityDigestNotificationDispatcher
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
        if (! in_array($kind, ServerSecurityDigestNotificationKeys::KINDS, true)) {
            return;
        }

        $detailLines = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $detailLines,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerSecurityDigestNotificationKeys::eventKey($kind);
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
            url: route('servers.security-digest', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.security_digest.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
