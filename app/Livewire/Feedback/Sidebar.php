<?php

declare(strict_types=1);

namespace App\Livewire\Feedback;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\FeedbackReport;
use App\Notifications\FeedbackReportSubmitted;
use App\Support\Admin\PlatformAdmins;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The global feedback / bug-report sidebar — mounted once in layouts/app.blade
 * so it is reachable from every authenticated page. The user picks a type, the
 * client auto-captures page context + a redacted screenshot + the JS console
 * buffer, and the report lands in the DB + notifies platform admins.
 */
class Sidebar extends Component
{
    use DispatchesToastNotifications;
    use WithFileUploads;

    public string $type = FeedbackReport::TYPE_BUG;

    public string $title = '';

    public string $description = '';

    public string $severity = 'normal';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $attachments = [];

    /**
     * Streamed screenshot upload (WebP). A real file upload — NOT a base64 string
     * property — so the multi-100KB image never inflates the component snapshot.
     */
    public $screenshotUpload = null;

    /** Small string fields populated by the Alpine driver right before submit(). */
    public ?string $consoleBuffer = null;

    public ?string $pageContext = null;

    public function updatedType(string $value): void
    {
        if (! in_array($value, FeedbackReport::typeKeys(), true)) {
            $this->type = FeedbackReport::TYPE_BUG;
        }
    }

    public function submit(): void
    {
        $limits = config('feedback.limits');

        $validated = $this->validate([
            'type' => ['required', 'string', 'in:'.implode(',', FeedbackReport::typeKeys())],
            'title' => ['required', 'string', 'max:'.$limits['title_max']],
            'description' => ['required', 'string', 'min:'.$limits['description_min'], 'max:'.$limits['description_max']],
            'severity' => ['nullable', 'string', 'in:'.implode(',', FeedbackReport::severityKeys())],
            'attachments' => ['array', 'max:'.$limits['attachments_max']],
            'attachments.*' => ['image', 'max:'.$limits['attachment_max_kb']],
            'screenshotUpload' => ['nullable', 'image', 'max:'.$limits['attachment_max_kb']],
        ]);

        $user = auth()->user();

        // Per-user rate limit (authenticated intake — every report ties to a real
        // account, so we key on the user, not email+ip).
        $key = 'feedback:'.($user?->getKey() ?? request()->ip());
        $maxAttempts = (int) config('feedback.rate_limit.max_attempts', 10);
        $decaySeconds = (int) config('feedback.rate_limit.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            $this->toastError(__('You have sent a lot of feedback recently. Try again in :minutes minutes.', [
                'minutes' => $minutes,
            ]));

            return;
        }

        $isBug = $validated['type'] === FeedbackReport::TYPE_BUG;

        $report = FeedbackReport::query()->create([
            'reference' => FeedbackReport::newReference(),
            'user_id' => $user?->getKey(),
            'organization_id' => $user?->currentOrganization()?->getKey(),
            'type' => $validated['type'],
            'severity' => $isBug ? ($validated['severity'] ?? 'normal') : null,
            'status' => FeedbackReport::STATUS_NEW,
            'title' => trim($validated['title']),
            'description' => trim($validated['description']),
            'context' => $this->buildContext(),
            'ip_address' => request()->ip(),
        ]);

        $screenshotPath = $this->storeScreenshot($report);
        $attachmentMeta = $this->storeAttachments($report);

        if ($screenshotPath !== null || $attachmentMeta !== []) {
            $report->forceFill([
                'screenshot_path' => $screenshotPath,
                'attachments' => $attachmentMeta ?: null,
            ])->save();
        }

        RateLimiter::hit($key, $decaySeconds);

        $this->notifyAdmins($report);

        $this->reset(['title', 'description', 'attachments', 'screenshotUpload', 'consoleBuffer', 'pageContext']);
        $this->severity = 'normal';

        $this->toastSuccess(__('Thanks — report :ref received.', ['ref' => $report->reference]));
        $this->dispatch('feedback-submitted');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        $limits = config('feedback.limits');

        $context = $this->decodeJson($this->pageContext);

        $console = $this->decodeJson($this->consoleBuffer);
        if (is_array($console)) {
            // Hard server-side caps regardless of what the client sent.
            $console = array_slice($console, -1 * (int) $limits['console_max_entries']);
            if (strlen((string) json_encode($console)) > (int) $limits['console_max_bytes']) {
                $console = array_slice($console, -10);
            }
            $context['console'] = $console;
        }

        $context['server'] = [
            'app_version' => config('app.version'),
            'submitted_at' => now()->toIso8601String(),
        ];

        return $context;
    }

    private function storeScreenshot(FeedbackReport $report): ?string
    {
        if (! $this->screenshotUpload) {
            return null;
        }

        return $this->screenshotUpload->storeAs(
            "reports/{$report->id}",
            'screenshot.webp',
            config('feedback.disk'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storeAttachments(FeedbackReport $report): array
    {
        $disk = config('feedback.disk');
        $meta = [];

        foreach ($this->attachments as $file) {
            $name = $file->getClientOriginalName();
            $stored = $file->storeAs("reports/{$report->id}/attachments", Str::ulid().'.'.$file->getClientOriginalExtension(), $disk);

            $meta[] = [
                'path' => $stored,
                'name' => $name,
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ];
        }

        return $meta;
    }

    private function notifyAdmins(FeedbackReport $report): void
    {
        $admins = PlatformAdmins::users();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new FeedbackReportSubmitted($report));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function render(): View
    {
        return view('livewire.feedback.sidebar', [
            'types' => config('feedback.types', []),
            'severities' => config('feedback.severities', []),
        ]);
    }
}
