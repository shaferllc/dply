<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Sites\OctaneRuntimeVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Silent background probe that confirms Laravel Octane is actually installed AND
 * serving a site on the box, recording the verdict via {@see OctaneRuntimeVerifier}
 * so the render-path {@see \App\Support\Sites\SitePipelineAdvisor} can gate the
 * "Reload Octane workers" deploy-step suggestion on a verified fact rather than a
 * composer.json mention. Triggered (debounced) from the Deploy panel's wire:init.
 *
 * Best-effort: an unreachable box or a pre-first-deploy path just leaves the
 * previous verdict in place and logs at debug — this never blocks anything.
 */
class VerifySiteOctaneJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $siteId)
    {
        $this->onQueue('dply-control');
    }

    /** One probe per site at a time; lock auto-releases so a stale lock can't wedge it. */
    public function uniqueId(): string
    {
        return 'octane-verify:'.$this->siteId;
    }

    public function uniqueFor(): int
    {
        return 120;
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if ($site === null || $site->server === null || ! $site->server->isVmHost()) {
            return;
        }

        $dir = rtrim($site->effectiveEnvDirectory(), '/');
        $port = $site->octane_port !== null ? (int) $site->octane_port : null;

        try {
            $output = $exec->runInlineBash(
                $site->server,
                'octane-verify',
                OctaneRuntimeVerifier::probeScript($dir, $port),
                45,
            );

            $verdict = OctaneRuntimeVerifier::interpret($output->buffer ?? '');
            OctaneRuntimeVerifier::persist($site, $verdict);
        } catch (\Throwable $e) {
            // Leave the previous verdict untouched — a transient SSH failure
            // shouldn't flip a working suggestion off (or on).
            Log::debug('VerifySiteOctaneJob probe failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
