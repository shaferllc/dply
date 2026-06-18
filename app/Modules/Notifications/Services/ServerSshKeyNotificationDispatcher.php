<?php

namespace App\Modules\Notifications\Services;

use App\Livewire\Servers\WorkspaceSshKeys;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerSshKeyNotificationKeys;

/**
 * Publishes notifications for server-scoped authorized-key CRUD. Called from the
 * SSH-keys workspace ({@see WorkspaceSshKeys}) once a key row
 * is added or removed, so operators can be alerted when the authorized set changes.
 *
 * Mirrors {@see ServerSystemUserNotificationDispatcher}. Subject is the {@see Server}
 * (valid even after a key row is deleted); URL points at the ssh-keys workspace.
 */
final class ServerSshKeyNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * @param  array<string, mixed> $keyNames  affected authorized-key labels
     * @param  array<string, mixed> $extraMetadata
     */
    public function notify(
        Server $server,
        string $kind,
        array $keyNames,
        ?User $actor = null,
        array $extraMetadata = [],
    ): void {
        if (! in_array($kind, ServerSshKeyNotificationKeys::KINDS, true)) {
            return;
        }

        $keyNames = array_values(array_filter(array_map(
            static fn ($n) => trim((string) $n),
            $keyNames,
        ), static fn (string $n) => $n !== ''));

        if ($keyNames === []) {
            return;
        }

        $eventKey = ServerSshKeyNotificationKeys::eventKey($kind);
        $count = count($keyNames);
        $list = implode(', ', $keyNames);

        $verb = $kind === 'removed' ? __('removed') : __('added');

        $title = $count === 1
            ? '['.config('app.name').'] '.$server->name.' — '.__('SSH key :verb', ['verb' => $verb])
            : '['.config('app.name').'] '.$server->name.' — '.__(':count SSH keys :verb', ['count' => $count, 'verb' => $verb]);

        $lines = [
            __('Server: :name', ['name' => $server->name]),
            $count === 1
                ? __('Key: :name', ['name' => $list])
                : __('Keys (:count): :names', ['count' => $count, 'names' => $list]),
        ];

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $server,
            title: $title,
            body: implode("\n", $lines),
            url: route('servers.ssh-keys', $server, absolute: true),
            metadata: array_merge([
                'server_id' => $server->id,
                'key_names' => $keyNames,
                'kind' => $kind,
            ], $extraMetadata),
            actor: $actor,
        );
    }
}
