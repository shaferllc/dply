<?php

namespace App\Jobs;

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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
    ) {}

    public function uniqueId(): string
    {
        return 'site-webserver-config:'.$this->siteId;
    }

    public function handle(SiteWebserverConfigApplier $applier): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        try {
            $applier->apply($site);
        } catch (\Throwable $e) {
            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['webserver_config_error'] = $e->getMessage();

            $site->update([
                'status' => Site::STATUS_ERROR,
                'meta' => $meta,
            ]);

            Log::warning('ApplySiteWebserverConfigJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
