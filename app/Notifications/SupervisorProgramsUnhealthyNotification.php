<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupervisorProgramsUnhealthyNotification extends Notification implements ShouldQueue
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
        $summary = (string) ($metadata['summary'] ?? $this->event->body ?? '');

        return (new MailMessage)
            ->subject($this->event->title ?: __('[:server] Supervisor programs need attention', ['server' => $serverName]))
            ->line(__('A scheduled health check reported an issue with Supervisor-managed programs on :server.', ['server' => $serverName]))
            ->line(__('Organization: :org', ['org' => $org]))
            ->when(filled($summary), fn (MailMessage $m) => $m->line($summary))
            ->when(filled($this->event->url), fn (MailMessage $m) => $m->action(__('Open Daemons'), $this->event->url));
    }

    /**
     * A dead worker pool silently stops processing jobs — nothing else surfaces
     * it, so it pages.
     */
    public function pagerDutySeverity(object $notifiable): ?string
    {
        return PagerDutyMessage::SEVERITY_ERROR;
    }

    public function pagerDutyDedupKey(object $notifiable): ?string
    {
        return 'dply:supervisor-unhealthy:'.$this->event->resource_id;
    }

    public function pagerDutySource(object $notifiable): string
    {
        return (string) ((($this->event->metadata ?? [])['server_name']) ?: config('app.name'));
    }
}
