<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\User;
use App\Support\ServerDatabaseNotificationKeys;

final class ServerDatabaseNotificationDispatcher
{
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

        $subs = NotificationSubscription::query()
            ->where('event_key', $eventKey)
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $server->id)
            ->with('channel')
            ->get()
            ->unique('notification_channel_id');

        if ($subs->isEmpty()) {
            return;
        }

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

        foreach ($subs as $sub) {
            $channel = $sub->channel;
            if (! $channel instanceof NotificationChannel) {
                continue;
            }

            $channel->sendOperationalMessage($subject, $text, $url, __('Open Databases'));
        }
    }
}
