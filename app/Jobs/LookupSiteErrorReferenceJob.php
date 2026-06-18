<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Sites\SiteErrorReferenceResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a server-error reference code (the `X-Dply-Ref` shown on the branded
 * 5xx page) to the actual request + error trace via {@see SiteErrorReferenceResolver}.
 *
 * Queued (SSH must never run inline) and streamed through a ConsoleAction so the
 * result surfaces in the Errors view's activity banner.
 */
class LookupSiteErrorReferenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public string $reference,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {}

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'error_reference_lookup';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteErrorReferenceResolver $resolver): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->info(__('Searching logs for reference :ref…', ['ref' => $this->reference]));

            $result = $resolver->resolve($site, $this->reference);

            if (! $result['found']) {
                $emit->warn($result['note'] ?? __('No matching request was found.'));
                $this->completeConsoleAction();

                return;
            }

            $emit->success(__('Request :request', ['request' => $result['request']]));
            if ($result['occurred_at'] !== null) {
                $emit->info(__('At :at', ['at' => $result['occurred_at']]));
            }

            // Lead with the single most actionable parsed line (highest severity,
            // app log preferred) before the full correlated dump below.
            if (($result['primary'] ?? null) !== null) {
                $primary = $result['primary'];
                $emit->error(__(':level: :message', [
                    'level' => $primary['level'] ?? __('ERROR'),
                    'message' => $primary['message'] ?? '',
                ]));
                // Emit the entire parsed stack trace — operators need the full
                // frame list to diagnose, and the console banner is scrollable
                // with a copy-all button, so there's no reason to truncate it.
                foreach ($primary['trace'] ?? [] as $traceLine) {
                    $emit->info($traceLine);
                }
            }

            if ($result['trace'] === []) {
                $emit->warn($result['note'] ?? __('No error line was located around that time.'));
            } else {
                $emit->info(__('Correlated error log lines:'));
                foreach ($result['trace'] as $line) {
                    $emit->info($line);
                }
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage());
            $this->failConsoleAction($e->getMessage());

            Log::warning('LookupSiteErrorReferenceJob failed', [
                'site_id' => $site->id,
                'reference' => $this->reference,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
