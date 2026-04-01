<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
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
        public NotificationEvent $event,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $metadata = $this->event->metadata ?? [];
        $serverName = (string) ($metadata['server_name'] ?? __('Server'));
        $org = (string) ($metadata['organization_name'] ?? __('your organization'));
        $context = (string) ($this->event->body ?? '');

        return (new MailMessage)
            ->subject($this->event->title ?: __('[:server] Server removed from Dply', ['server' => $serverName]))
            ->line(__('The server :name was removed from :org.', ['name' => $serverName, 'org' => $org]))
            ->when(filled($context), fn (MailMessage $m) => $m->line($context));
    }
}
