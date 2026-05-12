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

        $mail = (new MailMessage)
            ->subject('['.config('app.name').'] Backup failed: '.$target)
            ->line(__('A scheduled backup for :target on :server just failed.', [
                'target' => $target,
                'server' => $this->serverName ?: __('your server'),
            ]))
            ->line(__('Cadence: :cron', ['cron' => $this->schedule->cron_expression]));

        if (filled($this->errorMessage)) {
            $mail->line(__('Error: :err', ['err' => $this->errorMessage]));
        }

        $mail->action(__('Open Backups'), $url);
        $mail->line(__('After 3 consecutive failures the schedule auto-pauses to stop alert spam. Hit "Run now" once you fix the underlying issue and the schedule will resume on success.'));

        return $mail;
    }
}
