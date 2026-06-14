<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\FeedbackReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Loops the reporter back in when an admin moves their report to a resolved/
 * won't-fix state — bell + database only (these are signed-in internal users
 * who'll see the bell; no email). Dispatch is opt-in per admin action via the
 * "notify reporter" toggle on the triage form.
 */
class FeedbackReportStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public FeedbackReport $report,
        public ?string $note = null,
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
            'status' => $this->report->status,
            'title' => $this->report->title,
            'note' => $this->note,
            'event_key' => 'feedback.report.status_changed',
            'category' => 'feedback',
            'severity_level' => 'info',
            'body' => __('Your report “:title” is now :status.', [
                'title' => $this->report->title,
                'status' => $this->report->statusLabel(),
            ]),
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
}
