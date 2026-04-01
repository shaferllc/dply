<?php

namespace App\Services\Projects;

use App\Models\Workspace;
use App\Services\Notifications\NotificationPublisher;

class WorkspaceNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    public function notify(Workspace $workspace, string $eventKey, string $subject, string $text, ?string $url = null, ?string $actionLabel = null): void
    {
        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $workspace,
            title: $subject,
            body: $text,
            url: $url,
            metadata: [
                'action_label' => $actionLabel,
                'workspace_id' => $workspace->id,
            ],
        );
    }
}
