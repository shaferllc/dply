<?php

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

class SiteDeploymentCompletedNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public NotificationEvent $event
    ) {
        $this->onQueue((string) config('dply.notification_queue', 'default'));
    }

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

    /**
     * This fires on every deploy, good or bad. Only a failure is an incident.
     */
    public function pagerDutySeverity(object $notifiable): ?string
    {
        $status = (string) (($this->event->metadata ?? [])['status'] ?? 'completed');

        return in_array($status, ['failed', 'failure', 'error'], true)
            ? PagerDutyMessage::SEVERITY_ERROR
            : null;
    }

    /**
     * Keyed on the site, not the deployment: a site failing to deploy five times
     * running is one problem, and resolving it should close one incident.
     */
    public function pagerDutyDedupKey(object $notifiable): ?string
    {
        return 'dply:deploy-failed:'.$this->event->resource_id;
    }

    public function pagerDutySource(object $notifiable): string
    {
        return (string) ((($this->event->metadata ?? [])['site_name']) ?: config('app.name'));
    }
}
