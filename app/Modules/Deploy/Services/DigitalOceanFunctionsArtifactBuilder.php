<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use App\Support\DeployLogSanitizer;
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
        private readonly ServerlessBuildHostTools $buildHostTools,
        private readonly ServerlessAssetPublisher $assetPublisher,
    ) {}

    /**
     * @return array{artifact_path: string, working_directory: string, output: string}
     */
    public function build(Site $site): array
    {
        $site->loadMissing('server');

        $repositoryUrl = trim((string) $site->git_repository_url);
        if ($repositoryUrl === '') {
            throw new \RuntimeException('Choose a repository before deploying this serverless site.');
        }

        $resolvedConfig = $this->deploymentConfigResolver->resolve($site);

        $this->progress->active($site, 'checkout', 'Checking out the repository', $repositoryUrl);
        $checkout = $this->repositoryCheckout->checkout(
            'build-'.$site->id,
            $repositoryUrl,
            (string) ($site->git_branch ?: 'main'),
            (string) $resolvedConfig['repository_subdirectory'],
            $site->user_id,
            isset($resolvedConfig['source_control_account_id'])
                ? $resolvedConfig['source_control_account_id']
                : null,
            $site->gitRefKind(),
            function (string $chunk) use ($site): void {
                $this->progress->appendLog($site, $chunk);
            },
        );
        $this->progress->flushLog($site);
        $this->progress->done($site, 'checkout', 'Checked out the repository');

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

        $this->progress->active($site, 'detect', 'Detecting runtime');
        $detected = $this->runtimeDetector->detect(
            $checkout['working_directory'],
            $this->capabilityResolver->forSite($site),
        );
        $detectedLabel = trim((string) ($detected['framework'] ?? '')) !== ''
            && (string) $detected['framework'] !== 'unknown'
            ? (string) $detected['framework']
            : (string) ($detected['language'] ?? '');
        $this->progress->done(
            $site,
            'detect',
            'Detected runtime',
            $detectedLabel !== '' ? $detectedLabel : '',
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
        $isRawAction = ($detected['deploy_kind']) === 'raw'
            && trim((string) $detected['entry_file']) !== ''
            && in_array($detected['language'], ['node', 'python', 'php', 'go'], true);

        // DigitalOcean Functions runs PHP natively but ships no Laravel
        // bridge — inject the OpenWhisk↔Laravel adapter so the zipped repo
        // exposes the main() web action the runtime invokes. (AWS Lambda
        // takes the Bref path above instead.)
        $laravelAdapterLog = [];
        if ($detected['framework'] === 'laravel' && $site->server?->isDigitalOceanFunctionsHost()) {
            $this->progress->active($site, 'adapter', 'Injecting Laravel adapter', 'Functions ↔ Laravel bridge');
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
            $this->progress->active($site, 'adapter', 'Injecting Express adapter', 'Functions ↔ Express bridge');
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
            $this->progress->active($site, 'adapter', 'Injecting Flask adapter', 'Functions ↔ Flask bridge');
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
            $this->progress->active($site, 'adapter', 'Injecting Django adapter', 'Functions ↔ Django bridge');
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
            $this->progress->active($site, 'adapter', 'Injecting Gin adapter', 'Functions ↔ Gin bridge');
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
            $this->progress->active($site, 'adapter', 'Injecting logging shim', 'dply ↔ Functions raw-action bridge');
            $injection = $this->shimInjector->inject(
                $checkout['working_directory'],
                (string) $detected['language'],
                (string) $detected['entry_file'],
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
        $this->progress->active($site, 'environment', 'Preparing environment');
        $envLog = $this->environmentPreparer->prepare(
            $site,
            $checkout['working_directory'],
            $detected['framework'] === 'laravel',
        );
        $this->progress->done($site, 'environment', 'Prepared environment');

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
            $depLabel = ServerlessDeployProgress::dependenciesLabel($buildCommand);
            $this->progress->active($site, 'dependencies', $depLabel, $buildCommand);
            $log[] = $this->runShell($buildCommand, $checkout['working_directory'], $site);
            $this->progress->done($site, 'dependencies', str_replace('Installing', 'Installed', $depLabel));
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
            $log[] = $this->runShell($deployCommand, $checkout['working_directory'], $site);
            $this->progress->done($site, 'commands', 'Ran deploy commands');
        }

        $isLaravelFunctions = $detected['framework'] === 'laravel' && $site->server?->isDigitalOceanFunctionsHost();
        if ($isLaravelFunctions) {
            $optimizeLog = $this->optimizeLaravel($site, $checkout['working_directory']);
            if ($optimizeLog !== '') {
                $log[] = $optimizeLog;
            }

            $assetUrl = $this->assetPublisher->publishBuild($site, $checkout['working_directory']);
            if (is_string($assetUrl) && $assetUrl !== '') {
                $this->progress->active($site, 'assets', 'Publishing front-end assets');
                $this->environmentPreparer->applyAssetUrl($site, $checkout['working_directory'], $assetUrl);
                $this->progress->done($site, 'assets', 'Published front-end assets');
                $log[] = 'Published front-end assets at '.$assetUrl;
            }
        }

        $sourcePath = $checkout['working_directory'].'/'.ltrim($artifactOutputPath, '/');
        if (! file_exists($sourcePath)) {
            throw new \RuntimeException('Serverless build output was not found at: '.$artifactOutputPath);
        }

        $artifactDirectory = storage_path('app/serverless-artifacts/'.$site->id);
        File::ensureDirectoryExists($artifactDirectory);
        $artifactPath = $artifactDirectory.'/'.$this->artifactFilename($site);

        $this->progress->active($site, 'package', 'Packaging the artifact');
        if (is_file($sourcePath) && str_ends_with(strtolower($sourcePath), '.zip')) {
            File::copy($sourcePath, $artifactPath);
        } else {
            $this->zipPath($sourcePath, $artifactPath, $this->zipExclusions($checkout['working_directory']));
        }
        $this->progress->done($site, 'package', 'Packaged the artifact');

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
     * Zip one action's own source directory out of an already-checked-out
     * repository.
     *
     * {@see build()} produces the Site's primary artifact from the repo root.
     * A multi-function project has further actions living in subdirectories of
     * that same checkout, and each deploys as its own OpenWhisk action — so
     * each needs its own zip, cut from the tree build() already prepared
     * (dependencies installed, adapters injected, env written).
     *
     * `$include` / `$exclude` are the manifest's packaging filters. An
     * `include` list is a whitelist — only those paths are packaged, which is
     * how a function ships a static asset that lives outside its own tree.
     *
     * @param  string  $sourceSubdir  Repo-relative directory holding the action.
     * @param  list<string>  $include  Repo-relative paths to add on top of the action directory.
     * @param  list<string>  $exclude  Additional exclusion patterns.
     * @return string The artifact path.
     */
    public function packageAction(
        Site $site,
        string $workingDirectory,
        string $sourceSubdir,
        string $actionName,
        array $include = [],
        array $exclude = [],
    ): string {
        $sourceSubdir = trim($sourceSubdir, '/');
        if ($sourceSubdir === '') {
            throw new \RuntimeException('An additional action needs its own source directory.');
        }

        $realWorkingDirectory = realpath($workingDirectory);
        $sourcePath = realpath($workingDirectory.'/'.$sourceSubdir);

        // A manifest is repo-controlled input, so a `function:` path of
        // `../../etc` must not be able to package anything outside the
        // checkout.
        if ($realWorkingDirectory === false || $sourcePath === false
            || ! str_starts_with($sourcePath, $realWorkingDirectory.DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Action source directory escapes the repository: '.$sourceSubdir);
        }

        if (! is_dir($sourcePath)) {
            throw new \RuntimeException('Action source directory does not exist: '.$sourceSubdir);
        }

        $artifactDirectory = storage_path('app/serverless-artifacts/'.$site->id);
        File::ensureDirectoryExists($artifactDirectory);

        $slug = Str::slug($actionName) ?: 'action';
        $artifactPath = $artifactDirectory.'/'.$slug.'-'.now()->format('YmdHis').'.zip';

        $exclusions = array_values(array_unique(array_merge($this->zipExclusions($workingDirectory), $exclude)));
        $this->zipPath($sourcePath, $artifactPath, $exclusions);

        // Manifest `include` paths are resolved against the repo root, not the
        // action directory — that is what makes them useful: a shared assets/
        // or lib/ directory can be pulled into several functions' zips.
        foreach ($include as $path) {
            $this->addIncludedPath($artifactPath, $realWorkingDirectory, (string) $path);
        }

        return $artifactPath;
    }

    /**
     * Add one manifest `include` entry to an already-built action zip.
     *
     * A missing path is skipped rather than fatal: a manifest that lists an
     * optional asset directory should not break the deploy of a function that
     * works without it.
     */
    private function addIncludedPath(string $artifactPath, string $workingDirectory, string $path): void
    {
        $path = trim($path, '/');
        if ($path === '') {
            return;
        }

        $realPath = realpath($workingDirectory.'/'.$path);
        if ($realPath === false || ! str_starts_with($realPath, $workingDirectory.DIRECTORY_SEPARATOR)) {
            return;
        }

        $zip = new ZipArchive;
        if ($zip->open($artifactPath) !== true) {
            return;
        }

        if (is_file($realPath)) {
            $zip->addFile($realPath, $path);
        } elseif (is_dir($realPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $relative = ltrim(str_replace($realPath, '', $item->getPathname()), DIRECTORY_SEPARATOR);
                $localName = str_replace(DIRECTORY_SEPARATOR, '/', $path.'/'.$relative);

                if ($item->isDir()) {
                    $zip->addEmptyDir($localName);

                    continue;
                }

                $zip->addFile($item->getPathname(), $localName);
            }
        }

        $zip->close();
    }

    /**
     * Delete built artifact zips for the site that aren't in $keepPaths — the
     * retained rollback set (DO Functions keeps the last few in
     * artifact_history; Lambda keeps only the latest). Called after a
     * successful deploy so serverless-artifacts/<site> stops growing by one
     * zip per deploy. Best-effort: returns the count removed, never throws.
     *
     * @param  list<mixed>  $keepPaths
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

    /**
     * Bake `php artisan optimize` into the zip so cold starts load packaged
     * bootstrap/cache instead of compiling config/routes in /tmp.
     */
    private function optimizeLaravel(Site $site, string $workingDirectory): string
    {
        $dir = rtrim($workingDirectory, '/');
        if (! is_file($dir.'/artisan') || ! is_file($dir.'/vendor/autoload.php')) {
            return '';
        }

        $this->progress->active($site, 'optimize', 'Optimizing Laravel', 'php artisan optimize');

        try {
            $output = $this->runShell('php artisan optimize --no-interaction', $workingDirectory, $site);
            $this->progress->done($site, 'optimize', 'Optimized Laravel');

            return $output !== '' ? $output : 'Ran php artisan optimize.';
        } catch (\RuntimeException $e) {
            $this->progress->done($site, 'optimize', 'Skipped Laravel optimize');

            return 'Laravel optimize skipped: '.$e->getMessage();
        }
    }

    private function artifactFilename(Site $site): string
    {
        $base = Str::slug($site->slug !== '' ? $site->slug : $site->name);
        $base = $base !== '' ? $base : 'site';

        return $base.'-'.now()->format('YmdHis').'.zip';
    }

    private function runShell(string $command, string $workingDirectory, Site $site): string
    {
        // Control-plane workers often lack Composer and Node on PATH. Wrap
        // the command so the same shell installs them into storage/app/bin
        // when needed (same pattern as BYO SiteDeployPipelineRunner tooling
        // guards). Frontend compile (`npm ci` after composer) must not die
        // with a raw `sh: 1: npm: not found`.
        $prepared = $this->buildHostTools->prepareShellCommand($command);

        $process = Process::fromShellCommandline($prepared, $workingDirectory, $this->buildHostTools->processEnv());
        $process->setTimeout(1800);

        $captured = '';
        $process->run(function (string $type, string $buffer) use ($site, &$captured): void {
            $plain = $this->plain($buffer);
            if ($plain === '') {
                return;
            }
            $captured .= ($captured !== '' && ! str_ends_with($captured, "\n") ? "\n" : '').$plain;
            $this->progress->appendLog($site, $plain);
        });
        $this->progress->flushLog($site);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException($this->plain($process->getErrorOutput()."\n".$process->getOutput()));
        }

        return $captured !== '' ? $captured : $this->plain($process->getOutput());
    }

    /**
     * Build tools colour their output and animate progress with carriage
     * returns. Scrub that at the capture point so the control characters never
     * reach the deploy log, the Copy button, or a failure email.
     */
    private function plain(string $output): string
    {
        return trim(DeployLogSanitizer::sanitize($output));
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
     * @param  list<string>  $patterns
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
     * @param  list<string>  $excludePatterns
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
