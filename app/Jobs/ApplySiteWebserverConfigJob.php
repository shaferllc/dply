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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        $runId = (string) Str::ulid();
        $startedAt = now()->toIso8601String();

        $this->writeRunState($site, [
            config('site_webserver_config.meta_apply_run_id_key') => $runId,
            config('site_webserver_config.meta_apply_status_key') => 'running',
            config('site_webserver_config.meta_apply_started_at_key') => $startedAt,
            config('site_webserver_config.meta_apply_finished_at_key') => null,
            config('site_webserver_config.meta_apply_error_key') => null,
        ]);

        try {
            $output = $applier->apply($site);
            $this->cacheOutput($runId, $output);

            $this->writeRunState($site, [
                config('site_webserver_config.meta_apply_status_key') => 'completed',
                config('site_webserver_config.meta_apply_finished_at_key') => now()->toIso8601String(),
                config('site_webserver_config.meta_apply_error_key') => null,
            ]);
        } catch (\Throwable $e) {
            // Surface the exception message in the transcript so the operator sees something
            // even when the provisioner threw before the SSH command output was streamed.
            $this->cacheOutput($runId, $e->getMessage());

            $this->writeRunState($site, [
                config('site_webserver_config.meta_apply_status_key') => 'failed',
                config('site_webserver_config.meta_apply_finished_at_key') => now()->toIso8601String(),
                config('site_webserver_config.meta_apply_error_key') => $e->getMessage(),
                'webserver_config_error' => $e->getMessage(),
            ], statusOverride: Site::STATUS_ERROR);

            Log::warning('ApplySiteWebserverConfigJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Merge the given values into the site's meta JSON, preserving keys we don't touch.
     * Accepts a `statusOverride` so the failure path can flip the site to ERROR in one write.
     */
    private function writeRunState(Site $site, array $patch, ?string $statusOverride = null): void
    {
        $site->refresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($meta[$k]);

                continue;
            }
            $meta[$k] = $v;
        }

        $update = ['meta' => $meta];
        if ($statusOverride !== null) {
            $update['status'] = $statusOverride;
        }
        $site->update($update);
    }

    private function cacheOutput(string $runId, string $output): void
    {
        $maxLines = (int) config('site_webserver_config.apply_output_max_lines', 400);
        $lines = preg_split('/\r?\n/', rtrim($output, "\n")) ?: [];
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        Cache::put(
            config('site_webserver_config.apply_output_cache_key_prefix').$runId,
            ['lines' => array_values($lines)],
            (int) config('site_webserver_config.apply_output_cache_ttl_seconds', 300),
        );
    }
}
