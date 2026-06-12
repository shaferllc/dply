<?php

namespace App\Services\Notifications;

use App\Jobs\ToggleDatabaseNetworkingJob;
use App\Livewire\Servers\WorkspaceNetworking;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerNetworkingNotificationKeys;

/**
 * Publishes notifications for server-scoped networking changes (database/cache
 * exposure, private-network attach/detach, route changes), fired from the
 * networking workspace ({@see WorkspaceNetworking}) and the
 * {@see ToggleDatabaseNetworkingJob}.
 *
 * Mirrors {@see ServerSshKeyNotificationDispatcher}. Subject is the {@see Server}
 * whose networking changed; the per-kind title is pulled from the config label so
 * it stays in sync with the registry + bulk-assign UI.
 */
final class ServerNetworkingNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  list<string>  $resourceNames  affected resources (db/cache/network names, route CIDRs)
     * @param  array<string, mixed>  $extraMetadata
     */
    public function notify(
        Server $server,
        string $kind,
        array $resourceNames = [],
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerNetworkingNotificationKeys::KINDS, true)) {
            return;
        }

        $resourceNames = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $resourceNames,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerNetworkingNotificationKeys::eventKey($kind);
        $label = $this->label($eventKey, $kind);

        $title = '['.config('app.name').'] '.$server->name.' — '.$label;

        $lines = [__('Server: :name', ['name' => $server->name])];

        if ($resourceNames !== []) {
            $count = count($resourceNames);
            $lines[] = $count === 1
                ? __('Resource: :name', ['name' => $resourceNames[0]])
                : __('Resources (:count): :names', ['count' => $count, 'names' => implode(', ', $resourceNames)]);
        }

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            url: route('servers.networking', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'resource_names' => $resourceNames,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.networking.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
