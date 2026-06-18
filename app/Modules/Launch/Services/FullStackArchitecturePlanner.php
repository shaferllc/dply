<?php

declare(strict_types=1);

namespace App\Modules\Launch\Services;

use App\Services\Deploy\RuntimeDetection\GitCloneException;
use App\Services\Deploy\RuntimeDetection\GitCloner;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePlan;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePreview;
use App\Modules\Edge\Services\EdgeMonorepoDetector;
use App\Modules\Edge\Services\Frameworks\EdgeFrameworkPresetRegistry;
use App\Modules\Edge\Support\EdgeSsrDetection;
use App\Modules\Launch\Support\FullStackLaunchPlan;
use App\Modules\Launch\Support\FullStackLayer;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tier B: inspect a Git repo and recommend Edge + Cloud + BYO layers
 * with wiring hints — the seed for the full-stack launch wizard.
 */
final class FullStackArchitecturePlanner
{
    public function __construct(
        private RepositoryRuntimePreview $runtimePreview,
        private EdgeMonorepoDetector $monorepoDetector,
        private GitCloner $cloner,
    ) {}

    /**
     * @throws GitCloneException
     */
    public function planFromUrl(string $repo, string $branch = 'main'): FullStackLaunchPlan
    {
        $repo = trim($repo);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';

        if ($repo === '') {
            throw new \InvalidArgumentException('Repository URL is required.');
        }

        $tmpRoot = rtrim(sys_get_temp_dir(), '/').'/dply-fullstack-'.bin2hex(random_bytes(6));
        if (! @mkdir($tmpRoot, 0o700, true) && ! is_dir($tmpRoot)) {
            throw new GitCloneException("Could not create temp directory: {$tmpRoot}");
        }

        $checkoutPath = $tmpRoot.'/repo';

        try {
            $this->cloner->shallowClone($repo, $branch, $checkoutPath);

            return $this->planFromCheckout($repo, $branch, $checkoutPath);
        } finally {
            try {
                $this->deleteRecursive($tmpRoot);
            } catch (Throwable) {
                // Best-effort cleanup.
            }
        }
    }

    public function planFromCheckout(string $repo, string $branch, string $checkoutPath): FullStackLaunchPlan
    {
        $monorepo = $this->monorepoDetector->inspectDirectory($checkoutPath);
        $reasons = [];
        $warnings = [];
        $layers = [];

        if ($monorepo['is_monorepo']) {
            $reasons[] = 'Monorepo detected — analyzing package directories separately.';
            if ($monorepo['markers'] !== []) {
                $reasons[] = 'Markers: '.implode(', ', $monorepo['markers']).'.';
            }
        }

        $targets = $this->analysisTargets($checkoutPath, $monorepo);

        $nodeTarget = null;
        $phpTarget = null;

        foreach ($targets as $target) {
            $plan = $this->runtimePreview->fromPath($target['path']);
            if ($plan === null) {
                continue;
            }

            $planArray = $this->planToArray($plan, $target['repo_root']);
            $runtime = strtolower($plan->runtime);

            if ($runtime === 'node' && $nodeTarget === null) {
                $nodeTarget = [
                    'plan' => $plan,
                    'plan_array' => $planArray,
                    'repo_root' => $target['repo_root'],
                    'label' => $target['label'],
                ];
            }

            if ($runtime === 'php' && $phpTarget === null) {
                $phpTarget = [
                    'plan' => $plan,
                    'plan_array' => $planArray,
                    'repo_root' => $target['repo_root'],
                    'label' => $target['label'],
                ];
            }
        }

        if ($nodeTarget !== null) {
            $layers = array_merge($layers, $this->layersForNodeTarget($repo, $branch, $nodeTarget, $reasons));
        }

        if ($phpTarget !== null) {
            $layers[] = $this->layerForPhpTarget($repo, $branch, $phpTarget, $reasons);
        }

        if ($layers === []) {
            $rootPlan = $this->runtimePreview->fromPath($checkoutPath);
            if ($rootPlan !== null) {
                $planArray = $this->planToArray($rootPlan, '');
                $runtime = strtolower($rootPlan->runtime);
                if ($runtime === 'node') {
                    $layers = array_merge($layers, $this->layersForNodeTarget($repo, $branch, [
                        'plan' => $rootPlan,
                        'plan_array' => $planArray,
                        'repo_root' => '',
                        'label' => 'Repository root',
                    ], $reasons));
                } elseif ($runtime === 'php') {
                    $layers[] = $this->layerForPhpTarget($repo, $branch, [
                        'plan' => $rootPlan,
                        'plan_array' => $planArray,
                        'repo_root' => '',
                        'label' => 'Repository root',
                    ], $reasons);
                } else {
                    $warnings[] = "Detected runtime `{$rootPlan->runtime}` — full-stack wizard currently recommends Edge, Cloud, and BYO paths for Node and PHP repos.";
                }
            } else {
                $warnings[] = 'No supported runtime detected. Connect a Node or PHP app, or configure `dply.yaml` in the repo.';
            }
        }

        if ($this->shouldRecommendDatabase($layers)) {
            $layers[] = $this->databaseLayer($repo, $branch);
            $reasons[] = 'Full-stack apps typically need a managed database on a BYO server or external provider.';
        }

        $layers = $this->dedupeLayers($layers);

        return new FullStackLaunchPlan(
            repo: $repo,
            branch: $branch,
            isMonorepo: (bool) ($monorepo['is_monorepo']),
            layers: $layers,
            wiringHints: $this->wiringHints($layers),
            reasons: $reasons,
            warnings: $warnings,
        );
    }

    /**
     * @param  array{is_monorepo: bool, markers: list<string>, packages: list<array{path: string, label: string}>}  $monorepo
     * @return list<array{path: string, repo_root: string, label: string}>
     */
    private function analysisTargets(string $checkoutPath, array $monorepo): array
    {
        if (! ($monorepo['is_monorepo']) || ($monorepo['packages'] ?? []) === []) {
            return [[
                'path' => $checkoutPath,
                'repo_root' => '',
                'label' => 'Repository root',
            ]];
        }

        $targets = [];
        foreach ($monorepo['packages'] as $package) {
            $relative = trim((string) ($package['path'] ?? ''), '/');
            if ($relative === '' || $relative === '.') {
                continue;
            }
            $absolute = $checkoutPath.'/'.$relative;
            if (! is_dir($absolute)) {
                continue;
            }
            $targets[] = [
                'path' => $absolute,
                'repo_root' => $relative,
                'label' => (string) ($package['label']),
            ];
        }

        if ($targets === []) {
            return [[
                'path' => $checkoutPath,
                'repo_root' => '',
                'label' => 'Repository root',
            ]];
        }

        return $this->mergeComposerTargets($checkoutPath, $targets);
    }

    /**
     * @param  array<string, mixed> $targets
     * @return list<array{path: string, repo_root: string, label: string}>
     */
    private function mergeComposerTargets(string $checkoutPath, array $targets): array
    {
        $seen = collect($targets)->pluck('repo_root')->filter()->all();
        $composerRoots = array_filter([
            $checkoutPath,
            ...glob($checkoutPath.'/*', GLOB_ONLYDIR) ?: [],
            ...glob($checkoutPath.'/*/*', GLOB_ONLYDIR) ?: [],
        ], is_string(...));

        foreach ($composerRoots as $directory) {
            if (! is_file($directory.'/composer.json')) {
                continue;
            }

            $relative = trim(str_replace(rtrim($checkoutPath, '/'), '', $directory), '/');
            if ($relative !== '' && in_array($relative, $seen, true)) {
                continue;
            }

            $label = $relative === '' ? 'Repository root' : str_replace('/', ' / ', $relative);
            $targets[] = [
                'path' => $directory,
                'repo_root' => $relative,
                'label' => $label,
            ];
            if ($relative !== '') {
                $seen[] = $relative;
            }
        }

        return $targets;
    }

    /**
     * @param  array{plan: RepositoryRuntimePlan, plan_array: array<string, mixed>, repo_root: string, label: string}  $target
     * @param  array<string, mixed> $reasons
     * @return list<FullStackLayer>
     */
    private function layersForNodeTarget(string $repo, string $branch, array $target, array &$reasons): array
    {
        $planArray = $target['plan_array'];
        $repoRoot = $target['repo_root'];
        $label = $target['label'];
        $preset = EdgeFrameworkPresetRegistry::byDetectionPlan($planArray);
        $isSsr = EdgeSsrDetection::planLooksLikeSsr($planArray);
        $runtimeMode = $isSsr ? 'hybrid' : ($preset?->runtimeMode ?? 'static');

        $reasons[] = "{$label}: Node app detected".($planArray['framework'] ? ' ('.$planArray['framework'].')' : '').'.';

        $edgeParams = array_filter([
            'repo' => $repo,
            'branch' => $branch,
            'name' => $this->suggestedName($repo, 'edge'),
            'runtime_mode' => $runtimeMode,
            'build_command' => $planArray['build_command'] ?? null,
            'output_dir' => $preset->outputDir,
            'repo_root' => $repoRoot !== '' ? $repoRoot : null,
        ], fn ($value): bool => $value !== null && $value !== '');

        $layers = [
            new FullStackLayer(
                id: 'edge_front',
                engine: 'edge',
                label: __('Edge front'),
                description: $isSsr
                    ? __('Serve static assets at the edge and proxy SSR routes to your Cloud origin.')
                    : __('Build and deliver the frontend from dply Edge (CDN + previews).'),
                status: 'required',
                launchRoute: 'edge.create',
                launchParams: $edgeParams,
                repoRoot: $repoRoot !== '' ? $repoRoot : null,
                runtimeMode: $runtimeMode,
                framework: $planArray['framework'] ?? null,
            ),
        ];

        if ($isSsr) {
            $reasons[] = "{$label}: SSR framework detected — recommending hybrid Edge + Cloud stack.";
            $cloudParams = array_filter([
                'repo' => $repo,
                'branch' => $branch,
                'name' => $this->suggestedName($repo, 'api'),
                'repo_root' => $repoRoot !== '' ? $repoRoot : null,
            ], fn ($value): bool => $value !== null && $value !== '');

            $layers[] = new FullStackLayer(
                id: 'cloud_origin',
                engine: 'cloud',
                label: __('Cloud origin'),
                description: __('Run the SSR/API container on dply Cloud — Edge will origin-fetch dynamic routes.'),
                status: 'required',
                launchRoute: 'edge.create',
                launchParams: array_merge($edgeParams, ['runtime_mode' => 'hybrid']),
                repoRoot: $repoRoot !== '' ? $repoRoot : null,
                runtimeMode: 'hybrid',
                framework: $planArray['framework'] ?? null,
            );
        }

        return $layers;
    }

    /**
     * @param  array{plan: RepositoryRuntimePlan, plan_array: array<string, mixed>, repo_root: string, label: string}  $target
     * @param  array<string, mixed> $reasons
     */
    private function layerForPhpTarget(string $repo, string $branch, array $target, array &$reasons): FullStackLayer
    {
        $framework = (string) ($target['plan_array']['framework'] ?? 'php');
        $reasons[] = "{$target['label']}: PHP app detected ({$framework}).";

        return new FullStackLayer(
            id: 'byo_api',
            engine: 'byo',
            label: __('BYO API server'),
            description: __('Provision a VM, install nginx + PHP, and deploy the backend with git push deploys.'),
            status: 'recommended',
            launchRoute: 'servers.create',
            launchParams: array_filter([
                'repo' => $repo,
                'branch' => $branch,
            ]),
            repoRoot: $target['repo_root'] !== '' ? $target['repo_root'] : null,
            runtimeMode: 'vm',
            framework: $framework,
        );
    }

    private function databaseLayer(string $repo, string $branch): FullStackLayer
    {
        return new FullStackLayer(
            id: 'byo_database',
            engine: 'byo',
            label: __('Database host'),
            description: __('Add PostgreSQL or MySQL on a BYO server — restrict access to your app subnets and wire DATABASE_URL in each app.'),
            status: 'optional',
            launchRoute: 'servers.create',
            launchParams: [],
            runtimeMode: 'vm',
            framework: 'database',
        );
    }

    /**
     * @param  array<string, mixed> $layers
     */
    private function shouldRecommendDatabase(array $layers): bool
    {
        foreach ($layers as $layer) {
            if (in_array($layer->id, ['cloud_origin', 'byo_api', 'edge_front'], true) && $layer->status === 'required') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed> $layers
     * @return list<FullStackLayer>
     */
    private function dedupeLayers(array $layers): array
    {
        $seen = [];
        $deduped = [];

        foreach ($layers as $layer) {
            if (isset($seen[$layer->id])) {
                continue;
            }
            $seen[$layer->id] = true;
            $deduped[] = $layer;
        }

        return $deduped;
    }

    /**
     * @param  array<string, mixed> $layers
     * @return list<string>
     */
    private function wiringHints(array $layers): array
    {
        $hints = [];
        $hasEdge = false;
        $hasCloud = false;
        $hasByoApi = false;
        $hasDatabase = false;

        foreach ($layers as $layer) {
            match ($layer->id) {
                'edge_front' => $hasEdge = true,
                'cloud_origin' => $hasCloud = true,
                'byo_api' => $hasByoApi = true,
                'byo_database' => $hasDatabase = true,
                default => null,
            };
        }

        if ($hasEdge && $hasCloud) {
            $hints[] = 'Deploy the Cloud origin first, then create the hybrid Edge site — dply can link an existing Cloud app by repo or provision both in one step.';
            $hints[] = 'Point Edge SSR routes (for example `/api/*`) at the Cloud origin URL; static assets stay on R2 at the edge.';
        } elseif ($hasEdge) {
            $hints[] = 'Static Edge sites only need the Edge create flow — connect Git, verify build output dir, and enable deploy-on-push.';
        }

        if ($hasByoApi && $hasEdge) {
            $hints[] = 'Set the Edge hybrid origin (or env vars) to your BYO API URL once the VM site is live.';
        }

        if ($hasDatabase) {
            $hints[] = 'Open database ports only to app servers (BYO firewall or private network). Copy DATABASE_URL into Edge, Cloud, and BYO env settings.';
        }

        if (count($layers) >= 2) {
            $hints[] = 'After each layer is live, align environment variables across Edge, Cloud, and BYO — use Fleet → Env drift to compare preview vs production and linked sites from the same Git repo.';
        }

        if ($hasByoApi) {
            $hints[] = 'Use atomic deploys on the BYO site for zero-downtime API releases while Edge serves the frontend.';
        }

        if ($hints === []) {
            $hints[] = 'Review each layer below and launch the create flows in dependency order (database → API/origin → edge front).';
        }

        return $hints;
    }

    /**
     * @return array<string, mixed>
     */
    private function planToArray(RepositoryRuntimePlan $plan, string $repoRoot): array
    {
        return [
            'runtime' => $plan->runtime,
            'version' => $plan->version,
            'framework' => $plan->framework,
            'build_command' => $plan->buildCommand,
            'start_command' => $plan->startCommand,
            'app_port' => $plan->appPort,
            'confidence' => $plan->confidence,
            'repo_root' => $repoRoot,
        ];
    }

    private function suggestedName(string $repo, string $suffix): string
    {
        $base = basename(parse_url($repo, PHP_URL_PATH) ?: $repo);
        $base = preg_replace('/\.git$/', '', $base) ?: 'app';

        return Str::slug($base.'-'.$suffix);
    }

    private function deleteRecursive(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_link($path) || ! is_dir($path)) {
            @unlink($path);

            return;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteRecursive($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
