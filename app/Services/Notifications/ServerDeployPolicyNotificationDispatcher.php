<?php

namespace App\Services\Notifications;

use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Servers\WorkspaceDeployPolicy;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerDeployPolicyNotificationKeys;

/**
 * Publishes notifications for the server-wide deploy window policy — a deploy
 * blocked by a deny window ({@see RunSiteDeploymentJob}) and enforcement
 * toggled on / off ({@see WorkspaceDeployPolicy}).
 *
 * Mirrors {@see ServerCertInventoryNotificationDispatcher}. Subject is the
 * {@see Server} the policy belongs to; the per-kind title is the config label.
 */
final class ServerDeployPolicyNotificationDispatcher
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
        if (! in_array($kind, ServerDeployPolicyNotificationKeys::KINDS, true)) {
            return;
        }

        $detailLines = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $detailLines,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerDeployPolicyNotificationKeys::eventKey($kind);
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
            url: route('servers.deploy-policy', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.deploy_window.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
