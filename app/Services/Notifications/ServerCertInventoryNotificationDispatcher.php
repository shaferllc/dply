<?php

namespace App\Services\Notifications;

use App\Modules\Certificates\Jobs\ExecuteSiteCertificateJob;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerCertInventoryNotificationKeys;

/**
 * Publishes notifications for server-scoped TLS certificate lifecycle events
 * (issue / renewal success + failure), fired from {@see ExecuteSiteCertificateJob}.
 *
 * Mirrors {@see ServerWebserverNotificationDispatcher}. Subject is the {@see Server}
 * the certificate's site lives on; the per-kind title is pulled from the config label.
 */
final class ServerCertInventoryNotificationDispatcher
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
        if (! in_array($kind, ServerCertInventoryNotificationKeys::KINDS, true)) {
            return;
        }

        $detailLines = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $detailLines,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerCertInventoryNotificationKeys::eventKey($kind);
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
            url: route('servers.cert-inventory', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.cert_inventory.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
