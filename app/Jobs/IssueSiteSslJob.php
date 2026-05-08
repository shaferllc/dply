<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesSiteApplyState;
use App\Models\Site;
use App\Services\Sites\SiteSslProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IssueSiteSslJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesSiteApplyState;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public ?string $email = null,
    ) {}

    public function uniqueId(): string
    {
        return 'site-issue-ssl:'.$this->siteId;
    }

    protected function applyKind(): string
    {
        return 'ssl';
    }

    public function handle(SiteSslProvisioner $provisioner): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $runId = $this->beginApplyRun($site);

        try {
            $output = $provisioner->provision($site, $this->email);
            $this->cacheApplyOutput($runId, (string) $output);
            $this->completeApplyRun($site);
        } catch (\Throwable $e) {
            $this->cacheApplyOutput($runId, $e->getMessage());
            $this->failApplyRun($site, $e->getMessage());

            Log::warning('IssueSiteSslJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
