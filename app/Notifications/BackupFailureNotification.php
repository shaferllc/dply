<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BackupSchedule;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to org admins when a scheduled backup fails AND the schedule has
 * notify_on_failure enabled. Mirrors the shape of {@see CronJobAlertNotification}.
 */
class BackupFailureNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public BackupSchedule $schedule,
        public string $errorMessage = '',
        public string $serverName = '',
        public bool $isTest = false,
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
        $target = $this->schedule->targetLabel();
        $url = route('servers.backups', $this->schedule->server_id, absolute: true);

        $subjectPrefix = $this->isTest ? '[TEST] ' : '';
        $mail = (new MailMessage)
            ->subject($subjectPrefix.'['.config('app.name').'] Backup failed: '.$target);

        if ($this->isTest) {
            $mail->line(__('This is a test alert — no backup has failed. You triggered this from the Backups page to verify your email setup.'));
        } else {
            $mail->line(__('A scheduled backup for :target on :server just failed.', [
                'target' => $target,
                'server' => $this->serverName ?: __('your server'),
            ]));
        }

        $mail->line(__('Cadence: :cron', ['cron' => $this->schedule->cron_expression]));

        if (filled($this->errorMessage)) {
            $mail->line(__('Error: :err', ['err' => $this->errorMessage]));
        }

        $mail->action(__('Open Backups'), $url);

        if (! $this->isTest) {
            $mail->line(__('After 3 consecutive failures the schedule auto-pauses to stop alert spam. Hit "Run now" once you fix the underlying issue and the schedule will resume on success.'));
        }

        return $mail;
    }

    /**
     * Null for a test alert: the operator pressed a button to check their email
     * wiring and should not get paged for it.
     */
    public function pagerDutySeverity(object $notifiable): ?string
    {
        return $this->isTest ? null : PagerDutyMessage::SEVERITY_ERROR;
    }

    /**
     * Keyed on the schedule, so the three consecutive failures that auto-pause
     * it update one incident rather than opening three.
     */
    public function pagerDutyDedupKey(object $notifiable): ?string
    {
        return 'dply:backup-failed:'.$this->schedule->id;
    }

    public function pagerDutySource(object $notifiable): string
    {
        return $this->serverName ?: (string) $this->schedule->server_id;
    }
}
