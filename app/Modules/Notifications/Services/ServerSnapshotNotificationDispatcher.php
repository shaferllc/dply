<?php

namespace App\Modules\Notifications\Services;

use App\Livewire\Servers\WorkspaceSnapshots;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerSnapshotNotificationKeys;

/**
 * Publishes notifications for server-scoped snapshot changes (image/database/cache
 * snapshot create, database restore, and deletes), fired from the snapshots
 * workspace ({@see WorkspaceSnapshots}).
 *
 * Mirrors {@see ServerFirewallNotificationDispatcher}. Subject is the {@see Server};
 * the per-kind title is pulled from the config label, and the snapshot type travels
 * in the resource label + metadata.
 */
final class ServerSnapshotNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed> $resourceLabels  affected snapshot labels (e.g. "Image: web-1-…")
     * @param  array<string, mixed> $extraMetadata  should include `snapshot_type`
     */
    public function notify(
        Server $server,
        string $kind,
        array $resourceLabels = [],
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerSnapshotNotificationKeys::KINDS, true)) {
            return;
        }

        $resourceLabels = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $resourceLabels,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerSnapshotNotificationKeys::eventKey($kind);
        $label = $this->label($eventKey, $kind);

        $title = '['.config('app.name').'] '.$server->name.' — '.$label;

        $lines = [__('Server: :name', ['name' => $server->name])];

        if ($resourceLabels !== []) {
            $count = count($resourceLabels);
            $lines[] = $count === 1
                ? __('Snapshot: :name', ['name' => $resourceLabels[0]])
                : __('Snapshots (:count): :names', ['count' => $count, 'names' => implode(', ', $resourceLabels)]);
        }

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            url: route('servers.snapshots', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'snapshot_labels' => $resourceLabels,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.snapshot.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
