<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a server row is deleted (immediate UI action or scheduled job).
 */
class ServerRemovalExecutedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $serverName,
        public ?string $organizationName,
        public string $context,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! config('dply.server_deletion_notify_org_admins', true)) {
            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = $this->organizationName ?? __('your organization');

        return (new MailMessage)
            ->subject(__('[:server] Server removed from Dply', ['server' => $this->serverName]))
            ->line(__('The server :name was removed from :org.', ['name' => $this->serverName, 'org' => $org]))
            ->line($this->context);
    }
}
