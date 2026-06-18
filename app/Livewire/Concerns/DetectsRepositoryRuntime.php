<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\Deploy\Services\RuntimeDetection\GitCloneException;
use App\Modules\Deploy\Services\RuntimeDetection\RepositoryRuntimePlan;
use App\Modules\Deploy\Services\RuntimeDetection\RepositoryRuntimePreview;
use App\Modules\Deploy\Services\ServerlessRepositoryCheckout;
use App\Modules\Deploy\Services\ServerlessRuntimeDetector;
use App\Modules\Deploy\Services\ServerlessTargetCapabilityResolver;
use Throwable;

/**
 * Shared URL-first runtime detection for the create flows.
 *
 * The VM site, Cloud container, and serverless function create forms all want
 * the same "paste a repo, see what dply detected" affordance. This concern
 * carries the detection state + the two detector entry points + the panel's
 * input shape so each flow renders an identical detection panel instead of
 * copy-pasting the logic.
 *
 * Two detector backends feed one panel:
 *
 *   - {@see runDetection} — the general {@see RepositoryRuntimePreview}
 *     (dply.yaml manifest + runtime-detection engine). Used by VM + Cloud.
 *   - {@see runServerlessDetection} — the serverless {@see ServerlessRuntimeDetector}
 *     (framework vs. raw-action). Used by serverless create + the VM
 *     functions-host path.
 *
 * Both normalize into the same {@see $detectedPlan} array; serverless results
 * carry `kind => 'serverless'` so the panel can branch on it.
 *
 * The host component implements {@see applyDetectedRuntimePrefills} to copy
 * the result into its own (differently-named) form fields. Detection is always
 * non-blocking — any failure lands in `$detectedPlan['error']`, never thrown.
 */
trait DetectsRepositoryRuntime
{
    /**
     * The merged manifest+detection plan for the URL-first flow. Empty array
     * when no detection has run; otherwise an associative array of plan fields
     * (runtime, version, framework, build_command, start_command, app_port,
     * confidence, sources, processes, reasons, warnings, has_manifest) — or a
     * single-key `error` / `no_match` shape when detection could not produce
     * a plan. Serverless results additionally carry `kind => 'serverless'`.
     *
     * @var array<string, mixed>
     */
    public array $detectedPlan = [];

    /**
     * Suppress detection-driven form pre-fills once the user has manually
     * edited a managed field, so a re-detect doesn't stomp their edits.
     */
    public bool $runtimeOverridesTouched = false;

    /**
     * Copy the freshly-detected plan into the host's form fields. Called after
     * every detection run (success, error, no_match, or cleared). The host
     * reads {@see $detectedPlan} and should respect {@see $runtimeOverridesTouched}.
     */
    abstract protected function applyDetectedRuntimePrefills(): void;

    /**
     * URL-first general detection — clone the repo, run the dply.yaml +
     * runtime-detection engine, populate {@see $detectedPlan}.
     */
    public function runDetection(string $url, string $branch): void
    {
        $url = trim($url);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';

        if ($url === '') {
            $this->detectedPlan = [];
            $this->applyDetectedRuntimePrefills();

            return;
        }

        // Detection clones the repo (or pulls package.json) — for giant
        // monorepos like withastro/starlight this can blow past PHP's
        // 30s default and crash the whole Livewire request. Bump the
        // wall-clock cap so the worst case is a slow detect (not a
        // 500 page that the operator has to refresh out of).
        if (function_exists('set_time_limit')) {
            @set_time_limit(90);
        }

        try {
            $plan = app(RepositoryRuntimePreview::class)->fromUrl($url, $branch);
        } catch (GitCloneException|Throwable $e) {
            $this->detectedPlan = [
                'error' => $e->getMessage(),
                'url' => $url,
                'branch' => $branch,
            ];
            $this->applyDetectedRuntimePrefills();

            return;
        }

        if ($plan === null) {
            $this->detectedPlan = [
                'url' => $url,
                'branch' => $branch,
                'no_match' => true,
            ];
            $this->applyDetectedRuntimePrefills();

            return;
        }

        $this->detectedPlan = $this->planToArray($plan, $url, $branch);
        $this->applyDetectedRuntimePrefills();
    }

    /**
     * URL-first serverless detection — checks the repo out into the serverless
     * workspace, runs {@see ServerlessRuntimeDetector}, normalizes the flat
     * detector array into the same {@see $detectedPlan} shape.
     *
     * @param  array<string, mixed>  $capabilities  target capability map from
     *                                              {@see ServerlessTargetCapabilityResolver}
     */
    public function runServerlessDetection(string $url, string $branch, string $subdirectory, array $capabilities): void
    {
        $url = trim($url);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';

        if ($url === '') {
            $this->detectedPlan = [];
            $this->applyDetectedRuntimePrefills();

            return;
        }

        $checkout = null;

        try {
            $checkout = app(ServerlessRepositoryCheckout::class)->checkout(
                'preview-create-serverless-'.(string) auth()->id().'-'.md5($url.'|'.$branch.'|'.$subdirectory),
                $url,
                $branch,
                $subdirectory,
                auth()->id(),
                null,
            );

            $detection = app(ServerlessRuntimeDetector::class)->detect(
                $checkout['working_directory'],
                $capabilities,
            );

            $this->detectedPlan = $this->serverlessDetectionToArray($detection, $url, $branch);
        } catch (Throwable $e) {
            $this->detectedPlan = [
                'error' => $e->getMessage(),
                'url' => $url,
                'branch' => $branch,
                'kind' => 'serverless',
            ];
        } finally {
            if ($checkout !== null && isset($checkout['workspace_path']) && is_string($checkout['workspace_path'])) {
                app(ServerlessRepositoryCheckout::class)->cleanup($checkout['workspace_path']);
            }
        }

        $this->applyDetectedRuntimePrefills();
    }

    /**
     * Reconstruct a clone URL from either `owner/name` shorthand (what the
     * Cloud + serverless forms store) or a full URL. Empty input passes
     * through so callers can short-circuit.
     */
    public function normalizeToCloneUrl(string $repo): string
    {
        $repo = trim($repo);
        if ($repo === '') {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $repo) === 1 || str_starts_with($repo, 'git@')) {
            return $repo;
        }

        return 'https://github.com/'.trim($repo, '/').'.git';
    }

    /**
     * Render a {@see RepositoryRuntimePlan} into the panel's array shape.
     *
     * @return array<string, mixed>
     */
    protected function planToArray(RepositoryRuntimePlan $plan, string $url, string $branch): array
    {
        return [
            'url' => $url,
            'branch' => $branch,
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

    /**
     * Normalize the flat {@see ServerlessRuntimeDetector} array into the panel
     * shape. An `unknown` framework collapses to a `no_match` so the panel
     * renders the same "nothing detected" state as the general path.
     *
     * @param  array<string, mixed>  $detection
     * @return array<string, mixed>
     */
    protected function serverlessDetectionToArray(array $detection, string $url, string $branch): array
    {
        $framework = (string) ($detection['framework'] ?? 'unknown');

        if ($framework === 'unknown' || $framework === '') {
            return [
                'url' => $url,
                'branch' => $branch,
                'kind' => 'serverless',
                'no_match' => true,
            ];
        }

        return [
            'url' => $url,
            'branch' => $branch,
            'kind' => 'serverless',
            'runtime' => (string) ($detection['runtime'] ?? ''),
            'framework' => $framework,
            'deploy_kind' => (string) ($detection['deploy_kind'] ?? ''),
            'entrypoint' => (string) ($detection['entrypoint'] ?? ''),
            'build_command' => (string) ($detection['build_command'] ?? ''),
            'version' => null,
            'start_command' => null,
            'confidence' => (string) ($detection['confidence'] ?? ''),
            'reasons' => array_values((array) ($detection['reasons'] ?? [])),
            'warnings' => array_values((array) ($detection['warnings'] ?? [])),
            'processes' => [],
        ];
    }
}
