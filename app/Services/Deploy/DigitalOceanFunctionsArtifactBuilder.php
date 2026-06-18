<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
use App\Models\SiteDeployHook;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

class DigitalOceanFunctionsArtifactBuilder
{
    public function __construct(
        private readonly ServerlessRepositoryCheckout $repositoryCheckout,
        private readonly ServerlessRuntimeDetector $runtimeDetector,
        private readonly ServerlessTargetCapabilityResolver $capabilityResolver,
        private readonly ServerlessDeploymentConfigResolver $deploymentConfigResolver,
        private readonly BrefInjector $brefInjector,
        private readonly DigitalOceanFunctionsLaravelAdapter $laravelAdapter,
        private readonly ServerlessExpressAdapter $expressAdapter,
        private readonly ServerlessFlaskAdapter $flaskAdapter,
        private readonly ServerlessDjangoAdapter $djangoAdapter,
        private readonly ServerlessGinAdapter $ginAdapter,
        private readonly ServerlessLoggingShimInjector $shimInjector,
        private readonly ServerlessDeployProgress $progress,
        private readonly ServerlessEnvironmentPreparer $environmentPreparer,
        private readonly ServerlessDeployHookRunner $hookRunner,
    ) {}

    /**
     * @return array{artifact_path: string, working_directory: string, output: string}
     */
    /** @return array<string, mixed> */
    public function build(Site $site): array
    {
        $site->loadMissing('server');

        $repositoryUrl = trim((string) $site->git_repository_url);
        if ($repositoryUrl === '') {
            throw new \RuntimeException('Choose a repository before deploying this serverless site.');
        }

        $resolvedConfig = $this->deploymentConfigResolver->resolve($site);

        $this->progress->active($site, 'checkout', 'Cloning repository', $repositoryUrl);
        $checkout = $this->repositoryCheckout->checkout(
            'build-'.$site->id,
            $repositoryUrl,
            (string) ($site->git_branch ?: 'main'),
            (string) ($resolvedConfig['repository_subdirectory'] ?? ''),
            $site->user_id,
            isset($resolvedConfig['source_control_account_id']) && is_string($resolvedConfig['source_control_account_id'])
                ? $resolvedConfig['source_control_account_id']
                : null,
            $site->gitRefKind(),
        );
        $this->progress->done($site, 'checkout', 'Cloned repository');

        // before_clone hooks — operator shell that runs after checkout but
        // before the build (e.g. `npm ci && npm run build`).
        $beforeBuildHookLog = $this->runHooks(
            $site, SiteDeployHook::PHASE_BEFORE_CLONE, 'hooks_before', $checkout['working_directory']
        );

        // AWS Lambda PHP targets run via Bref — auto-inject it into the
        // checked-out app so the user's repo carries no serverless boilerplate.
        // DO Functions has a native PHP runtime and needs no injection.
        $brefLog = [];
        if ($site->server?->isAwsLambdaHost()) {
            $injection = $this->brefInjector->inject($checkout['working_directory']);
            if ($injection['ran']) {
                $brefLog[] = $injection['output'];
            }
        }

        $detected = $this->runtimeDetector->detect(
            $checkout['working_directory'],
            $this->capabilityResolver->forSite($site),
        );

        if ($detected['unsupported_for_target']) {
            throw new \RuntimeException((string) ($detected['warnings'][0] ?? 'The detected runtime is not supported by this target.'));
        }

        $buildCommand = trim((string) ($resolvedConfig['build_command'] !== '' ? $resolvedConfig['build_command'] : $detected['build_command']));
        $artifactOutputPath = trim((string) ($resolvedConfig['artifact_output_path'] !== '' ? $resolvedConfig['artifact_output_path'] : $detected['artifact_output_path']));
        $runtime = trim((string) ($resolvedConfig['runtime'] !== '' ? $resolvedConfig['runtime'] : $detected['runtime']));
        $entrypoint = trim((string) ($resolvedConfig['entrypoint'] !== '' ? $resolvedConfig['entrypoint'] : $detected['entrypoint']));
        $package = trim((string) ($resolvedConfig['package'] !== '' ? $resolvedConfig['package'] : $detected['package']));

        // A raw OpenWhisk action — a bare main() detected at the repo root,
        // with no framework. It gets a logging shim rather than a framework
        // adapter, and (unlike a framework build) may legitimately have no
        // build step at all.
        $isRawAction = ($detected['deploy_kind'] ?? '') === 'raw'
            && trim((string) ($detected['entry_file'] ?? '')) !== ''
            && in_array($detected['language'], ['node', 'python', 'php', 'go'], true);

        // DigitalOcean Functions runs PHP natively but ships no Laravel
        // bridge — inject the OpenWhisk↔Laravel adapter so the zipped repo
        // exposes the main() web action the runtime invokes. (AWS Lambda
        // takes the Bref path above instead.)
        $laravelAdapterLog = [];
        if ($detected['framework'] === 'laravel' && $site->server?->isDigitalOceanFunctionsHost()) {
            $this->progress->active($site, 'adapter', 'Injecting Laravel adapter', 'DigitalOcean Functions ↔ Laravel bridge');
            $injection = $this->laravelAdapter->inject($checkout['working_directory']);
            if ($injection['ran']) {
                $laravelAdapterLog[] = $injection['output'];
                $entrypoint = DigitalOceanFunctionsLaravelAdapter::HANDLER_FUNCTION;
                if (! str_starts_with($runtime, 'php')) {
                    // Laravel 13 needs PHP >= 8.4; default there when the
                    // form did not already pick a PHP runtime.
                    $runtime = 'php:8.4';
                }
            }
            $this->progress->done($site, 'adapter', 'Injected Laravel adapter');
        }

        // DigitalOcean Functions runs Node natively but cannot serve an
        // Express app directly — inject the OpenWhisk↔Express adapter so the
        // zipped repo exposes the main() web action the runtime invokes.
        $expressAdapterLog = [];
        if ($detected['framework'] === 'express' && $site->server?->isDigitalOceanFunctionsHost()) {
            $this->progress->active($site, 'adapter', 'Injecting Express adapter', 'DigitalOcean Functions ↔ Express bridge');
            $injection = $this->expressAdapter->inject($checkout['working_directory']);
            if ($injection['ran']) {
                $expressAdapterLog[] = $injection['output'];
                $entrypoint = ServerlessExpressAdapter::HANDLER_FUNCTION;
                if (! str_starts_with($runtime, 'node')) {
                    $runtime = (string) ($this->capabilityResolver->forSite($site)['default_runtime'] ?: 'nodejs:18');
                }
            }
            $this->progress->done($site, 'adapter', 'Injected Express adapter');
        }

        // DigitalOcean Functions runs Python natively but cannot serve a
        // Flask app directly — inject the OpenWhisk↔Flask WSGI adapter so the
        // zipped repo exposes the main() web action the runtime invokes.
        $flaskAdapterLog = [];
        if ($detected['framework'] === 'flask' && $site->server?->isDigitalOceanFunctionsHost()) {
            $this->progress->active($site, 'adapter', 'Injecting Flask adapter', 'DigitalOcean Functions ↔ Flask bridge');
            $injection = $this->flaskAdapter->inject($checkout['working_directory']);
            if ($injection['ran']) {
                $flaskAdapterLog[] = $injection['output'];
                $entrypoint = ServerlessFlaskAdapter::HANDLER_FUNCTION;
            }
            $this->progress->done($site, 'adapter', 'Injected Flask adapter');
        }

        // Django ships its own WSGI entrypoint — inject the OpenWhisk↔WSGI
        // adapter pointed at the project's wsgi.py.
        $djangoAdapterLog = [];
        if ($detected['framework'] === 'django' && $site->server?->isDigitalOceanFunctionsHost()) {
            $this->progress->active($site, 'adapter', 'Injecting Django adapter', 'DigitalOcean Functions ↔ Django bridge');
            $injection = $this->djangoAdapter->inject($checkout['working_directory']);
            if ($injection['ran']) {
                $djangoAdapterLog[] = $injection['output'];
                $entrypoint = ServerlessDjangoAdapter::HANDLER_FUNCTION;
            }
            $this->progress->done($site, 'adapter', 'Injected Django adapter');
        }

        // A Gin app deploys as a Go action — inject the OpenWhisk↔Gin
        // adapter, which drives the repo's exported Router().
        $ginAdapterLog = [];
        if ($detected['framework'] === 'gin' && $site->server?->isDigitalOceanFunctionsHost()) {
            $this->progress->active($site, 'adapter', 'Injecting Gin adapter', 'DigitalOcean Functions ↔ Gin bridge');
            $injection = $this->ginAdapter->inject($checkout['working_directory']);
            if ($injection['ran']) {
                $ginAdapterLog[] = $injection['output'];
                $entrypoint = ServerlessGinAdapter::HANDLER_FUNCTION;
            }
            $this->progress->done($site, 'adapter', 'Injected Gin adapter');
        }

        // A raw action has no dply-injected handler, so organic invocations
        // would be invisible (the DO activations list API is empty). Inject
        // the per-language logging shim: it becomes the OpenWhisk entry,
        // wraps the repo's own action, and reports each call to dply.
        $shimLog = [];
        if ($isRawAction && $site->server?->isDigitalOceanFunctionsHost()) {
            $this->progress->active($site, 'adapter', 'Injecting logging shim', 'dply ↔ OpenWhisk raw-action bridge');
            $injection = $this->shimInjector->inject(
                $checkout['working_directory'],
                (string) $detected['language'],
                (string) ($detected['entry_file'] ?? ''),
            );
            if ($injection['ran']) {
                $shimLog[] = $injection['output'];
                $entrypoint = $injection['function'];
            }
            $this->progress->done($site, 'adapter', 'Injected logging shim');
        }

        // Bundle dply's managed environment into the artifact (and mint a
        // stable APP_KEY for Laravel) — the function has no other way to
        // receive configuration.
        $envLog = $this->environmentPreparer->prepare(
            $site,
            $checkout['working_directory'],
            $detected['framework'] === 'laravel',
        );

        // A raw action with no dependency manifest needs no build step; for
        // anything else an empty build command is a misconfiguration.
        if ($buildCommand === '' && ! $isRawAction) {
            throw new \RuntimeException('Dply could not determine a build command for this serverless site. Open Advanced settings and set one manually.');
        }

        if ($artifactOutputPath === '') {
            throw new \RuntimeException('Dply could not determine a build output path for this serverless site. Open Advanced settings and set one manually.');
        }

        $resolvedConfig = $this->deploymentConfigResolver->persistResolvedConfig($site, [
            'runtime' => $runtime,
            'entrypoint' => $entrypoint,
            'package' => $package,
            'build_command' => $buildCommand,
            'artifact_output_path' => $artifactOutputPath,
        ]);

        $log = array_filter([$checkout['output'], $beforeBuildHookLog, ...$brefLog, ...$laravelAdapterLog, ...$expressAdapterLog, ...$flaskAdapterLog, ...$djangoAdapterLog, ...$ginAdapterLog, ...$shimLog, $envLog]);

        if ($buildCommand !== '') {
            $this->progress->active($site, 'dependencies', 'Installing dependencies', $buildCommand);
            $log[] = $this->runShell($buildCommand, $checkout['working_directory']);
            $this->progress->done($site, 'dependencies', 'Installed dependencies');
        }

        // after_clone hooks — operator shell that runs once dependencies are
        // installed but before the artifact is packaged.
        $afterBuildHookLog = $this->runHooks(
            $site, SiteDeployHook::PHASE_AFTER_CLONE, 'hooks_after', $checkout['working_directory']
        );
        if ($afterBuildHookLog !== '') {
            $log[] = $afterBuildHookLog;
        }

        // Deploy commands — migrations, cache warming, etc. Run in the build
        // environment after dependencies + the prepared .env are in place, so
        // they see the function's real configuration. A failure aborts the
        // deploy rather than shipping a half-migrated app.
        $deployCommand = trim((string) $site->post_deploy_command);
        if ($deployCommand !== '') {
            $this->progress->active($site, 'commands', 'Running deploy commands', $deployCommand);
            $log[] = $this->runShell($deployCommand, $checkout['working_directory']);
            $this->progress->done($site, 'commands', 'Ran deploy commands');
        }

        $sourcePath = $checkout['working_directory'].'/'.ltrim($artifactOutputPath, '/');
        if (! file_exists($sourcePath)) {
            throw new \RuntimeException('Serverless build output was not found at: '.$artifactOutputPath);
        }

        $artifactDirectory = storage_path('app/serverless-artifacts/'.$site->id);
        File::ensureDirectoryExists($artifactDirectory);
        $artifactPath = $artifactDirectory.'/'.$this->artifactFilename($site);

        $this->progress->active($site, 'package', 'Packaging artifact');
        if (is_file($sourcePath) && str_ends_with(strtolower($sourcePath), '.zip')) {
            File::copy($sourcePath, $artifactPath);
        } else {
            $this->zipPath($sourcePath, $artifactPath, $this->zipExclusions($checkout['working_directory']));
        }
        $this->progress->done($site, 'package', 'Packaged artifact');

        return [
            'artifact_path' => $artifactPath,
            'working_directory' => $checkout['working_directory'],
            'output' => trim(implode("\n", array_filter(array_merge(
                $log,
                [
                    'Detected framework: '.$detected['framework'],
                    'Detected language: '.$detected['language'],
                    'Resolved runtime: '.$resolvedConfig['runtime'],
                    'Resolved entrypoint: '.$resolvedConfig['entrypoint'],
                ]
            )))),
        ];
    }

    /**
     * Delete built artifact zips for the site that aren't in $keepPaths — the
     * retained rollback set (DO Functions keeps the last few in
     * artifact_history; Lambda keeps only the latest). Called after a
     * successful deploy so serverless-artifacts/<site> stops growing by one
     * zip per deploy. Best-effort: returns the count removed, never throws.
     *
     * @param  array<string, mixed> $keepPaths
     */
    public function pruneArtifactsExcept(Site $site, array $keepPaths): int
    {
        $dir = storage_path('app/serverless-artifacts/'.$site->id);
        if (! File::isDirectory($dir)) {
            return 0;
        }

        $keep = [];
        foreach ($keepPaths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }
            $keep[realpath($path) ?: $path] = true;
        }

        $removed = 0;
        foreach (File::files($dir) as $file) {
            $real = realpath($file->getPathname()) ?: $file->getPathname();
            if (isset($keep[$real])) {
                continue;
            }

            try {
                File::delete($file->getPathname());
                $removed++;
            } catch (\Throwable) {
                // best-effort — a stuck file shouldn't disturb the deploy
            }
        }

        return $removed;
    }

    /**
     * Run a deploy-hook phase as a journey sub-step, returning its transcript
     * (empty when the site has no hooks for the phase, so the step is skipped).
     */
    private function runHooks(Site $site, string $phase, string $stepKey, string $workingDirectory): string
    {
        $site->loadMissing('deployHooks');
        if ($site->deployHooks->where('phase', $phase)->isEmpty()) {
            return '';
        }

        $label = ServerlessDeployHookRunner::PHASE_LABELS[$phase].' hooks';
        $this->progress->active($site, $stepKey, $label);
        $output = $this->hookRunner->runPhase($site, $phase, $workingDirectory);
        $this->progress->done($site, $stepKey, $label);

        return $output;
    }

    private function artifactFilename(Site $site): string
    {
        $base = Str::slug($site->slug !== '' ? $site->slug : $site->name);
        $base = $base !== '' ? $base : 'site';

        return $base.'-'.now()->format('YmdHis').'.zip';
    }

    /**
     * @param  array<string, mixed> $command
     */
    private function run(array $command, string $workingDirectory): string
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()));
        }

        return trim($process->getOutput());
    }

    private function runShell(string $command, string $workingDirectory): string
    {
        $process = Process::fromShellCommandline($command, $workingDirectory);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()));
        }

        return trim($process->getOutput());
    }

    /** Paths never worth shipping in a serverless action artifact. */
    private const DEFAULT_EXCLUSIONS = [
        '.git', '.github', '.gitlab', 'node_modules', '.idea', '.vscode', '.DS_Store',
    ];

    /**
     * Build the artifact exclusion list — sensible defaults plus anything in
     * a repo-root `.dplyignore` (gitignore-style: one path per line, `#`
     * comments). Keeps the action zip lean and well under the size limit.
     *
     * @return list<string>
     */
    private function zipExclusions(string $workingDirectory): array
    {
        $patterns = self::DEFAULT_EXCLUSIONS;

        $ignoreFile = rtrim($workingDirectory, '/').'/.dplyignore';
        if (is_file($ignoreFile)) {
            foreach (file($ignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $patterns[] = trim($line, '/');
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @param  array<string, mixed> $patterns
     */
    private function isExcluded(string $localName, array $patterns): bool
    {
        $localName = str_replace(DIRECTORY_SEPARATOR, '/', $localName);
        $segments = explode('/', $localName);

        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if ($localName === $pattern
                || str_starts_with($localName, $pattern.'/')
                || in_array($pattern, $segments, true)
                || fnmatch($pattern, $localName)
                || fnmatch($pattern, basename($localName))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed> $excludePatterns
     */
    private function zipPath(string $sourcePath, string $artifactPath, array $excludePatterns = []): void
    {
        $zip = new ZipArchive;
        if ($zip->open($artifactPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create serverless artifact zip.');
        }

        if (is_dir($sourcePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $localName = ltrim(str_replace($sourcePath, '', $item->getPathname()), DIRECTORY_SEPARATOR);
                if ($localName === '' || $this->isExcluded($localName, $excludePatterns)) {
                    continue;
                }

                if ($item->isDir()) {
                    $zip->addEmptyDir(str_replace(DIRECTORY_SEPARATOR, '/', $localName));

                    continue;
                }

                $zip->addFile($item->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $localName));
            }
        } else {
            $zip->addFile($sourcePath, basename($sourcePath));
        }

        $zip->close();
    }
}
