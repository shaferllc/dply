<?php

namespace App\Modules\Notifications\Services;

use App\Livewire\Servers\WorkspaceLoadBalancers;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerLoadBalancerNotificationKeys;

/**
 * Publishes notifications for server-scoped load-balancer changes (create/delete,
 * target add/remove), fired from the load-balancers workspace
 * ({@see WorkspaceLoadBalancers}).
 *
 * Mirrors {@see ServerFirewallNotificationDispatcher}. Subject is the {@see Server}
 * whose workspace triggered the change; the per-kind title is pulled from the config
 * label so it stays in sync with the registry + bulk-assign UI.
 */
final class ServerLoadBalancerNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed> $resourceNames  affected resources (load balancer / target names)
     * @param  array<string, mixed> $extraMetadata
     */
    public function notify(
        Server $server,
        string $kind,
        array $resourceNames = [],
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerLoadBalancerNotificationKeys::KINDS, true)) {
            return;
        }

        $resourceNames = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $resourceNames,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerLoadBalancerNotificationKeys::eventKey($kind);
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
            url: route('servers.load-balancers', $server, absolute: true),
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
        $events = (array) config('notification_events.categories.load_balancer.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
