<?php

namespace App\Services\Notifications;

use App\Jobs\ServerManageRemoteSshJob;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerPatchNotificationKeys;

/**
 * Publishes notifications for server-scoped OS patch / update actions (apt upgrade,
 * dist-upgrade, reboot, unattended-upgrades toggle), fired from the shared
 * manage-action job ({@see ServerManageRemoteSshJob}) for patch task names.
 *
 * Mirrors {@see ServerWebserverNotificationDispatcher}. Subject is the {@see Server};
 * the per-kind title is pulled from the config label.
 */
final class ServerPatchNotificationDispatcher
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
        if (! in_array($kind, ServerPatchNotificationKeys::KINDS, true)) {
            return;
        }

        $detailLines = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $detailLines,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerPatchNotificationKeys::eventKey($kind);
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
            url: route('servers.patches', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.patches.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
