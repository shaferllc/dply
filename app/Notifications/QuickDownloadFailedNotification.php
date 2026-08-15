<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\QuickDownload;
use App\Modules\Notifications\Channels\PagerDuty\PagerDutyMessage;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Notifications\Concerns\DeliversToPagerDuty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the user who requested a quick download when the build or upload
 * fails — most commonly because the artifact exceeded the quick-download size
 * cap. Points them at a scheduled backup for the over-cap case. In-app delivery
 * is handled separately via the notification inbox.
 */
class QuickDownloadFailedNotification extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use DeliversToPagerDuty;
    use Queueable;

    public function __construct(
        public QuickDownload $quickDownload,
        public string $label,
        public string $reason,
        public string $backupsUrl,
        public bool $overCap = false,
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
        $mail = (new MailMessage)
            ->error()
            ->subject('['.config('app.name').'] '.__('Download failed: :label', ['label' => $this->label]))
            ->line(__('We couldn’t prepare your :label download.', ['label' => $this->label]));

        if ($this->reason !== '') {
            $mail->line(__('Reason: :reason', ['reason' => $this->reason]));
        }

        if ($this->overCap) {
            $mail->line(__('Quick downloads are capped — for larger artifacts, use a scheduled backup instead, which streams without the size limit.'));
        }

        return $mail->action(__('Open Backups'), $this->backupsUrl);
    }

    /**
     * Warning, not error: a failed download blocks one person's restore, it does
     * not mean the platform is down. Over-cap is a quota decision rather than a
     * fault, so it does not page at all.
     */
    public function pagerDutySeverity(object $notifiable): ?string
    {
        return $this->overCap ? null : PagerDutyMessage::SEVERITY_WARNING;
    }

    public function pagerDutyDedupKey(object $notifiable): ?string
    {
        return 'dply:quick-download-failed:'.$this->quickDownload->id;
    }

    public function pagerDutySource(object $notifiable): string
    {
        return $this->label ?: (string) config('app.name');
    }
}
