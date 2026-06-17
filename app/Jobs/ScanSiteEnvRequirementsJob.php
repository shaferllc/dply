<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Sites\SiteEnvRequirementScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scans a site's deployed code for the env vars it expects (see
 * {@see SiteEnvRequirementScanner}) and caches the result in
 * sites.meta['env_requirements'] so the Environment UI can flag missing
 * required keys without an SSH round-trip on render.
 *
 * Dispatched two ways:
 *   - silently after a successful deploy (no console run) to keep the list
 *     fresh as the code changes;
 *   - from the "Re-scan" button with a seeded console run so progress streams
 *     into the page-top banner (same pattern as env push/sync).
 *
 * One-in-flight-per-site via {@see ShouldBeUnique}: a deploy-triggered scan
 * and a manual re-scan coalesce rather than racing on the meta write.
 */
class ScanSiteEnvRequirementsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {}

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'console-action:env_scan:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'env_scan';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteEnvRequirementScanner $scanner): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if (! $site || ! $site->server) {
            return;
        }

        // Only VM-style hosts have on-disk code to scan; container/serverless
        // runtimes inject env at build time and have no live tree here.
        if (! $site->server->hostCapabilities()->supportsEnvPushToHost()) {
            return;
        }

        $emit = null;
        if ($this->seededConsoleRunId !== null) {
            $this->bindConsoleRunId($this->seededConsoleRunId);
            $emit = $this->beginConsoleAction();
            $emit->step('scan', __('Scanning code for required environment variables'));
        }

        try {
            $result = $scanner->scan($site);

            $meta = $site->meta;
            $meta['env_requirements'] = $result;
            $site->forceFill(['meta' => $meta])->save();

            if ($emit !== null) {
                $required = count(array_filter($result['keys'], static fn (array $k): bool => $k['required']));
                $emit->success(__(':count required variable(s) detected from the code.', ['count' => $required]));
                $this->completeConsoleAction();
            }
        } catch (\Throwable $e) {
            if ($emit !== null) {
                $emit->error($e->getMessage(), 'scan');
                $this->failConsoleAction($e->getMessage());
            }

            Log::warning('ScanSiteEnvRequirementsJob failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
