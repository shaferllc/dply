<?php

namespace App\Services\Notifications;

use App\Jobs\ApplyFirewallJob;
use App\Livewire\Servers\WorkspaceFirewall;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerFirewallNotificationKeys;

/**
 * Publishes notifications for server-scoped firewall changes (rule create/update/
 * delete and host apply), fired from the firewall workspace
 * ({@see WorkspaceFirewall}) and {@see ApplyFirewallJob}.
 *
 * Mirrors {@see ServerNetworkingNotificationDispatcher}. Subject is the {@see Server};
 * the per-kind title is pulled from the config label so it stays in sync with the
 * registry + bulk-assign UI.
 */
final class ServerFirewallNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed> $ruleLabels  affected rule labels (or "N rule(s)" for bulk/apply)
     * @param  array<string, mixed> $extraMetadata
     */
    public function notify(
        Server $server,
        string $kind,
        array $ruleLabels = [],
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerFirewallNotificationKeys::KINDS, true)) {
            return;
        }

        $ruleLabels = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $ruleLabels,
        ), static fn (string $n) => $n !== ''));

        $eventKey = ServerFirewallNotificationKeys::eventKey($kind);
        $label = $this->label($eventKey, $kind);

        $title = '['.config('app.name').'] '.$server->name.' — '.$label;

        $lines = [__('Server: :name', ['name' => $server->name])];

        if ($ruleLabels !== []) {
            $count = count($ruleLabels);
            $lines[] = $count === 1
                ? __('Rule: :name', ['name' => $ruleLabels[0]])
                : __('Rules (:count): :names', ['count' => $count, 'names' => implode(', ', $ruleLabels)]);
        }

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            url: route('servers.firewall', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'rule_labels' => $ruleLabels,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.firewall_rule.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
