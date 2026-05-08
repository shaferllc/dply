<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesSiteApplyState;
use App\Models\Site;
use App\Services\Sites\SiteWebserverConfigApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplySiteWebserverConfigJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesSiteApplyState;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
    ) {}

    public function uniqueId(): string
    {
        return 'site-webserver-config:'.$this->siteId;
    }

    protected function applyKind(): string
    {
        return 'webserver_config';
    }

    public function handle(SiteWebserverConfigApplier $applier): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $runId = $this->beginApplyRun($site);

        try {
            $output = $applier->apply($site);
            $this->cacheApplyOutput($runId, $output);
            $this->completeApplyRun($site);
        } catch (\Throwable $e) {
            // Surface the exception message in the transcript so the operator sees
            // something even when the provisioner threw before the SSH command output
            // was streamed.
            $this->cacheApplyOutput($runId, $e->getMessage());
            $this->failApplyRun(
                $site,
                $e->getMessage(),
                statusOverride: Site::STATUS_ERROR,
                extraMeta: ['webserver_config_error' => $e->getMessage()],
            );

            Log::warning('ApplySiteWebserverConfigJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
