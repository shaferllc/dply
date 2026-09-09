<?php

declare(strict_types=1);

namespace App\Modules\Feedback\Notifications;

use App\Models\FeedbackReport;
use App\Notifications\Concerns\DeliversToIntercom;
use App\Notifications\Concerns\DeliversToMicrosoftTeams;
use App\Support\Admin\PlatformAdmins;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Fired to every platform admin when a new feedback/bug report lands.
 *
 * Always database + broadcast (lights up the notification bell in real time).
 * Email is added ONLY for high/critical-severity bugs so routine feedback does
 * not spam admin inboxes.
 *
 * @see PlatformAdmins::users()
 */
class FeedbackReportSubmitted extends Notification implements ShouldQueue
{
    use DeliversToIntercom;
    use DeliversToMicrosoftTeams;
    use Queueable;

    public function __construct(
        public FeedbackReport $report
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (config('broadcasting.default') !== 'null') {
            $channels[] = 'broadcast';
        }

        if ($this->report->isHighPriority()) {
            $channels[] = 'mail';
            // Intercom rides the same high-priority gate as e-mail rather than
            // firing on every report — same reason: routine feedback shouldn't
            // spam admins, and Intercom is the noisier of the two.
            $channels = array_merge($channels, $this->viaIntercom($notifiable), $this->viaMicrosoftTeams($notifiable));
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'feedback_report_id' => $this->report->id,
            'reference' => $this->report->reference,
            'type' => $this->report->type,
            'severity' => $this->report->severity,
            'title' => $this->report->title,
            'url' => route('admin.feedback.index', ['report' => $this->report->id]),
            'event_key' => 'feedback.report.submitted',
            'category' => 'feedback',
            'severity_level' => $this->report->isHighPriority() ? 'warning' : 'info',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function broadcastType(): string
    {
        return 'universal.notification';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('admin.feedback.index', ['report' => $this->report->id]);

        return (new MailMessage)
            ->subject(__(':severity bug report: :title', [
                'severity' => ucfirst((string) $this->report->severity),
                'title' => $this->report->title,
            ]))
            ->greeting(__('New :severity bug report', ['severity' => $this->report->severity]))
            ->line(__('Reference: :ref', ['ref' => $this->report->reference]))
            ->line($this->report->title)
            ->line(Str::limit($this->report->description, 500))
            ->action(__('Open in admin'), $url);
    }
}
