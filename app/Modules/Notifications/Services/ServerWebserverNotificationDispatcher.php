<?php

namespace App\Modules\Notifications\Services;

use App\Jobs\RevertServerWebserverSwitchJob;
use App\Jobs\RunWebserverConfigOpJob;
use App\Jobs\SwitchServerWebserverJob;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerWebserverNotificationKeys;

/**
 * Publishes notifications for server-scoped webserver changes (engine switch,
 * rollback, config-file save), fired from the webserver jobs
 * ({@see SwitchServerWebserverJob}, {@see RevertServerWebserverSwitchJob},
 * {@see RunWebserverConfigOpJob}).
 *
 * Mirrors {@see ServerFirewallNotificationDispatcher}. Subject is the {@see Server};
 * the per-kind title is pulled from the config label.
 */
final class ServerWebserverNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed> $detailLines  human detail (e.g. "nginx → caddy", "caddy: /etc/caddy/Caddyfile")
     * @param  array<string, mixed> $extraMetadata
     */
    public function notify(
        Server $server,
        string $kind,
        array $detailLines = [],
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerWebserverNotificationKeys::KINDS, true)) {
            return;
        }

        $detailLines = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $detailLines,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerWebserverNotificationKeys::eventKey($kind);
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
            url: route('servers.webserver', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.webserver.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
