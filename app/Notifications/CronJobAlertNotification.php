<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CronJobAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public NotificationEvent $event,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
            ? __('Non-zero exit code (:code).', ['code' => (string) ($exitCode ?? '?')])
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
}
