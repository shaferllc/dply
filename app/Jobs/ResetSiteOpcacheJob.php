<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Sites\SiteOpcacheManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Flushes a site's PHP-FPM OPcache via {@see SiteOpcacheManager} (FastCGI to the
 * pool socket — clears the real FPM cache, not a CLI one). Queued + streamed
 * through a console action so the page-top banner confirms the result.
 */
class ResetSiteOpcacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {}

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'opcache_reset';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteOpcacheManager $opcache): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->info(__('Flushing OPcache for :pool…', ['pool' => $site->phpFpmPoolName()]));

            $result = $opcache->reset($site);

            if ($result === null || empty($result['ok'])) {
                $reason = is_array($result) ? ($result['error'] ?? 'unknown error') : 'no response from FPM';
                throw new \RuntimeException("OPcache reset did not run ({$reason}).");
            }

            if (array_key_exists('reset', $result) && $result['reset'] === false) {
                // opcache_reset() returns false when OPcache is disabled.
                $emit->warn(__('OPcache is not enabled for this pool — nothing to flush.'));
            } else {
                $emit->success(__('OPcache flushed. Workers will recompile on the next request.'));
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage());
            $this->failConsoleAction($e->getMessage());

            Log::warning('ResetSiteOpcacheJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
