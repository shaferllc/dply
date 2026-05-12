<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ServerBackupSchedule;
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
    use Queueable;

    public function __construct(
        public ServerBackupSchedule $schedule,
        public string $errorMessage = '',
        public string $serverName = '',
        public bool $isTest = false,
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
}
