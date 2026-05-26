<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePlan;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Off-thread repository runtime detection for the Edge create form.
 *
 * Cloning a repo + composing a plan can take 30-90s for big monorepos
 * (e.g. withastro/starlight). Running that inside a Livewire request
 * blows past PHP's 30s wall-clock and crashes the page. This job runs
 * the same detection on the queue, writes the result into Cache, and
 * the front end polls a status method to pick it up.
 */
class DetectRepositoryRuntimeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Wall-clock cap on the job (giant monorepos can still take a minute). */
    public int $timeout = 180;

    public function __construct(
        public string $cacheKey,
        public string $url,
        public string $branch,
    ) {}

    public function handle(RepositoryRuntimePreview $preview): void
    {
        // Mark as "running" so a second dispatch doesn't double-queue
        // while we're working.
        Cache::put($this->cacheKey, [
            'state' => 'running',
            'url' => $this->url,
            'branch' => $this->branch,
        ], now()->addMinutes(15));

        try {
            $plan = $preview->fromUrl($this->url, $this->branch);

            if ($plan === null) {
                Cache::put($this->cacheKey, [
                    'state' => 'done',
                    'plan' => [
                        'url' => $this->url,
                        'branch' => $this->branch,
                        'no_match' => true,
                    ],
                ], now()->addMinutes(15));

                return;
            }

            Cache::put($this->cacheKey, [
                'state' => 'done',
                'plan' => $this->planToArray($plan),
            ], now()->addMinutes(15));
        } catch (Throwable $e) {
            Cache::put($this->cacheKey, [
                'state' => 'failed',
                'plan' => [
                    'error' => $e->getMessage(),
                    'url' => $this->url,
                    'branch' => $this->branch,
                ],
            ], now()->addMinutes(15));
        }
    }

    /**
     * Mirror of DetectsRepositoryRuntime::planToArray. Inlined to avoid
     * a trait-import cycle from a job.
     *
     * @return array<string, mixed>
     */
    private function planToArray(RepositoryRuntimePlan $plan): array
    {
        return [
            'url' => $this->url,
            'branch' => $this->branch,
            'runtime' => $plan->runtime,
            'version' => $plan->version,
            'framework' => $plan->framework,
            'build_command' => $plan->buildCommand,
            'start_command' => $plan->startCommand,
            'app_port' => $plan->appPort,
            'output_dir' => $plan->detection?->outputDirectory,
            'confidence' => $plan->confidence,
            'sources' => $plan->sources,
            'reasons' => $plan->reasons,
            'warnings' => $plan->warnings,
            'has_manifest' => $plan->hasManifest(),
            'processes' => array_map(
                fn ($p) => [
                    'type' => $p->type,
                    'name' => $p->name,
                    'command' => $p->command,
                    'reason' => $p->reason,
                ],
                $plan->processes,
            ),
        ];
    }
}
