<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SiteDeploymentCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public NotificationEvent $event
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
        $siteName = (string) ($metadata['site_name'] ?? 'Site');
        $status = (string) ($metadata['status'] ?? 'completed');
        $trigger = (string) ($metadata['trigger'] ?? 'manual');
        $gitSha = $metadata['git_sha'] ?? null;
        $logExcerpt = $metadata['log_excerpt'] ?? null;
        $subject = $this->event->title ?: '['.config('app.name').'] Deploy '.strtoupper($status).' — '.$siteName;

        $mail = (new MailMessage)
            ->subject($subject)
            ->line('Site: **'.$siteName.'**')
            ->line('Trigger: '.$trigger)
            ->line('Status: **'.$status.'**');

        if (filled($gitSha)) {
            $mail->line('Git SHA: `'.$gitSha.'`');
        }

        if (filled($this->event->url)) {
            $mail->action('Open site in Dply', $this->event->url);
        }

        if (filled($logExcerpt)) {
            $mail->line('Log excerpt:')->line('```'.PHP_EOL.$logExcerpt.PHP_EOL.'```');
        }

        return $mail;
    }
}
