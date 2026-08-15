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

class CronJobAlertNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public NotificationEvent $event,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return array_merge(['mail'], $this->viaIntercom($notifiable), $this->viaMicrosoftTeams($notifiable), $this->viaPagerDuty($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $metadata = $this->event->metadata ?? [];
        $serverName = (string) ($metadata['server_name'] ?? __('Server'));
        $cronJobDescription = (string) ($metadata['cron_job_description'] ?? __('Cron job'));
        $exitCode = $metadata['exit_code'] ?? '—';
        $failure = (bool) ($metadata['failure'] ?? false);
        $outputExcerpt = (string) ($metadata['output_excerpt'] ?? '');
        $reason = $failure
            ? __('Non-zero exit code (:code).', ['code' => (string) $exitCode])
            : __('Output matched your alert pattern.');

        $mail = (new MailMessage)
            ->subject($this->event->title ?: __('[:app] Cron job alert: :server', ['app' => config('app.name'), 'server' => $serverName]))
            ->line(__('Cron job “:desc” on server :server.', [
                'desc' => $cronJobDescription,
                'server' => $serverName,
            ]))
            ->line($reason)
            ->line(__('Exit code: :code', ['code' => (string) $exitCode]));

        if (filled($outputExcerpt)) {
            $mail->line($outputExcerpt);
        }

        if (filled($this->event->url)) {
            $mail->action(__('Open cron jobs'), $this->event->url);
        }

        return $mail;
    }

    /**
     * Only a non-zero exit pages. This notification also fires for informational
     * cron transitions, and those are not incidents.
     */
    public function pagerDutySeverity(object $notifiable): ?string
    {
        return (bool) (($this->event->metadata ?? [])['failure'] ?? false)
            ? PagerDutyMessage::SEVERITY_ERROR
            : null;
    }

    public function pagerDutyDedupKey(object $notifiable): ?string
    {
        return 'dply:cron:'.$this->event->resource_id.':'.(($this->event->metadata ?? [])['cron_job_id'] ?? $this->event->event_key);
    }

    public function pagerDutySource(object $notifiable): string
    {
        return (string) ((($this->event->metadata ?? [])['server_name']) ?: config('app.name'));
    }
}
