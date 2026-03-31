<?php

namespace App\Services\Projects;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Workspace;

class WorkspaceNotificationDispatcher
{
    public function notify(Workspace $workspace, string $eventKey, string $subject, string $text, ?string $url = null, ?string $actionLabel = null): void
    {
        $subs = NotificationSubscription::query()
            ->where('event_key', $eventKey)
            ->where('subscribable_type', Workspace::class)
            ->where('subscribable_id', $workspace->id)
            ->with('channel')
            ->get()
            ->unique('notification_channel_id');

        foreach ($subs as $sub) {
            $channel = $sub->channel;
            if (! $channel instanceof NotificationChannel) {
                continue;
            }

            $channel->sendOperationalMessage($subject, $text, $url, $actionLabel);
        }
    }
}
