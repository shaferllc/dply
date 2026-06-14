<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FeedbackReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Bounds disk growth for the feedback feature: report ROWS are kept forever
 * (the text + context is a cheap, valuable trail), but their stored bytes —
 * screenshot + manual attachments — are deleted once a report has been in a
 * terminal status (resolved/closed/won't-fix/duplicate) longer than the
 * configured retention window. Admin then shows a "screenshot expired"
 * placeholder via the attachments_pruned_at marker.
 *
 * @see config/feedback.php attachment_retention_days
 */
class PruneFeedbackAttachmentsCommand extends Command
{
    protected $signature = 'dply:prune-feedback-attachments';

    protected $description = 'Delete screenshots/attachments for long-closed feedback reports.';

    public function handle(): int
    {
        $days = (int) config('feedback.attachment_retention_days', 90);
        $cutoff = now()->subDays($days);
        $disk = Storage::disk(config('feedback.disk'));

        $reports = FeedbackReport::query()
            ->whereIn('status', FeedbackReport::TERMINAL_STATUSES)
            ->whereNull('attachments_pruned_at')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $cutoff)
            ->where(function ($q): void {
                $q->whereNotNull('screenshot_path')->orWhereNotNull('attachments');
            })
            ->get();

        $count = 0;

        foreach ($reports as $report) {
            // Delete the whole per-report folder in one shot (screenshot + attachments).
            $disk->deleteDirectory("reports/{$report->id}");

            $report->forceFill([
                'screenshot_path' => null,
                'attachments' => null,
                'attachments_pruned_at' => now(),
            ])->save();

            $count++;
        }

        $this->info("Pruned attachments for {$count} closed feedback report(s).");

        return self::SUCCESS;
    }
}
