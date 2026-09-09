<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServerRemovalScheduledNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public NotificationEvent $event,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return array_merge(['mail'], $this->viaIntercom($notifiable), $this->viaMicrosoftTeams($notifiable), $this->viaPagerDuty($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $metadata = $this->event->metadata ?? [];
        $serverName = (string) ($metadata['server_name'] ?? __('Server'));
        $org = (string) ($metadata['organization_name'] ?? __('your organization'));
        $scheduledForDisplay = (string) ($metadata['scheduled_for_display'] ?? __('Soon'));
        $actorName = (string) ($metadata['actor_name'] ?? __('Someone'));
        $reason = $metadata['reason'] ?? null;

        return (new MailMessage)
            ->subject($this->event->title ?: __('[:server] Server removal scheduled', ['server' => $serverName]))
            ->line(__('A server in :org is scheduled for removal.', ['org' => $org]))
            ->line(__('Server: :name', ['name' => $serverName]))
            ->line(__('Removal window ends: :when', ['when' => $scheduledForDisplay]))
            ->line(__('Scheduled by: :who', ['who' => $actorName]))
            ->when(filled($reason), fn (MailMessage $m) => $m->line(__('Reason: :r', ['r' => $reason])))
            ->when(filled($this->event->url), fn (MailMessage $m) => $m->action(__('Open server'), $this->event->url));
    }
}
