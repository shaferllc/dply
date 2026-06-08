<?php

namespace App\Services\Notifications;

use App\Models\Server;
use App\Models\User;
use App\Support\ServerSystemUserNotificationKeys;

/**
 * Publishes notifications for server-scoped Linux account CRUD. Called from the
 * system-user jobs (create/update/delete/orphan-cleanup) once the SSH mutation has
 * succeeded, so both the workspace and API paths fan out through one place.
 *
 * The subject is the {@see Server} (not the ServerSystemUser row) because deletion
 * removes that row before this fires — the server resolves stakeholders, org/team
 * context, and we override the URL to the system-users workspace.
 */
final class ServerSystemUserNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  list<string>  $usernames  affected accounts; for create/update this is a single name,
     *                                    for orphan cleanup it can be several. Empty = nothing to report.
     * @param  array<string, mixed>  $extraMetadata
     */
    public function notify(
        Server $server,
        string $kind,
        array $usernames,
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerSystemUserNotificationKeys::KINDS, true)) {
            return;
        }

        $usernames = array_values(array_filter(array_map(
            static fn ($u) => trim((string) $u),
            $usernames,
        ), static fn (string $u) => $u !== ''));

        if ($usernames === []) {
            return;
        }

        $eventKey = ServerSystemUserNotificationKeys::eventKey($kind);
        $count = count($usernames);
        $list = implode(', ', $usernames);

        $verb = match ($kind) {
            'created' => __('created'),
            'updated' => __('updated'),
            'removed' => __('removed'),
            default => __('updated'),
        };

        $title = $count === 1
            ? '['.config('app.name').'] '.$server->name.' — '.__('system user :verb', ['verb' => $verb])
            : '['.config('app.name').'] '.$server->name.' — '.__(':count system users :verb', ['count' => $count, 'verb' => $verb]);

        $lines = [
            __('Server: :name', ['name' => $server->name]),
            $count === 1
                ? __('Account: :name', ['name' => $list])
                : __('Accounts (:count): :names', ['count' => $count, 'names' => $list]),
        ];

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            url: route('servers.system-users', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'usernames' => $usernames,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }
}
