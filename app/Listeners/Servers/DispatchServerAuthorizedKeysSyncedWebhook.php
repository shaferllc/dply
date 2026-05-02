<?php

namespace App\Listeners\Servers;

use App\Events\Servers\ServerAuthorizedKeysSynced;
use App\Services\Webhooks\OutboundWebhookDispatcher;

class DispatchServerAuthorizedKeysSyncedWebhook
{
    public function __construct(private OutboundWebhookDispatcher $dispatcher) {}

    public function handle(ServerAuthorizedKeysSynced $event): void
    {
        $server = $event->server->fresh();
        if ($server === null) {
            return;
        }

        $this->dispatcher->dispatchForServer(
            'server.authorized_keys.synced',
            $server,
            [
                'initiated_by_user_id' => $event->initiatedBy?->id,
                'summary' => $event->summary,
                'data' => $event->payload,
            ],
            $event->summary
        );
    }
}
