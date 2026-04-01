<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupervisorProgramsUnhealthyNotification extends Notification implements ShouldQueue
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
        $summary = (string) ($metadata['summary'] ?? $this->event->body ?? '');

        return (new MailMessage)
            ->subject($this->event->title ?: __('[:server] Supervisor programs need attention', ['server' => $serverName]))
            ->line(__('A scheduled health check reported an issue with Supervisor-managed programs on :server.', ['server' => $serverName]))
            ->line(__('Organization: :org', ['org' => $org]))
            ->when(filled($summary), fn (MailMessage $m) => $m->line($summary))
            ->when(filled($this->event->url), fn (MailMessage $m) => $m->action(__('Open Daemons'), $this->event->url));
    }
}
