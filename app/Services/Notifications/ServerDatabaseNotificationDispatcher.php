<?php

namespace App\Services\Notifications;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\User;
use App\Support\ServerDatabaseNotificationKeys;

final class ServerDatabaseNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    public function notifyIfSubscribed(
        Server $server,
        string $kind,
        ServerDatabase $database,
        ?User $actor = null,
        ?bool $droppedFromServer = null,
    ): void {
        if (! in_array($kind, ServerDatabaseNotificationKeys::KINDS, true)) {
            return;
        }

        $eventKey = ServerDatabaseNotificationKeys::eventKey($kind);

        $subject = match ($kind) {
            'created' => '['.config('app.name').'] '.$server->name.' — database created',
            'removed' => '['.config('app.name').'] '.$server->name.' — database removed',
            default => '['.config('app.name').'] '.$server->name.' — database update',
        };

        $lines = [
            __('Server: :name', ['name' => $server->name]),
            __('Database: :name', ['name' => $database->name]),
            __('Engine: :engine', ['engine' => $database->engine === 'postgres' ? 'PostgreSQL' : 'MySQL / MariaDB']),
            __('User: :user', ['user' => $database->username]),
        ];

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        if ($kind === 'removed' && $droppedFromServer !== null) {
            $lines[] = $droppedFromServer
                ? __('Removal: Dropped from the server and removed from Dply')
                : __('Removal: Removed from Dply only');
        }

        $text = implode("\n", $lines);
        $url = route('servers.databases', $server, absolute: true);

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $database,
            title: $subject,
            body: $text,
            url: $url,
            metadata: [
                'server_id' => $server->id,
                'database_id' => $database->id,
                'database_name' => $database->name,
                'kind' => $kind,
                'dropped_from_server' => $droppedFromServer,
            ],
            actor: $actor,
        );
    }
}
