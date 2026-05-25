<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Services\Edge\Config\EdgeRepoConfigLinter;
use App\Services\Edge\Config\EdgeRepoConfigLoader;
use App\Services\Edge\Ssr\EdgeSsrFrameworkRegistry;
use App\Support\Edge\EdgeRepoRoot;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class EdgeBuildRunner
{
    /** Static / SSG sites — output is a directory of files served from R2. */
    public const MODE_STATIC = 'static';

    /** Hybrid sites — same static output, plus a configured origin proxy. */
    public const MODE_HYBRID = 'hybrid';

    /**
     * SSR sites — Next.js + @opennextjs/cloudflare produces both a static
     * assets directory AND a Worker script that ships to a per-deployment
     * dispatch namespace. See {@see EdgeSsrBundleUploader}.
     */
    public const MODE_SSR = 'ssr';

    /** Cap per-script source bytes to avoid blowing past CF's 10 MB Worker limit. */
    private const SSR_SCRIPT_MAX_BYTES = 9 * 1024 * 1024;

    /**
     * @param  array<string, string>  $env
     * @return array{
     *     artifact_dir: string,
     *     build_log: string,
     *     git_commit: ?string,
     *     git_commit_subject?: ?string,
     *     git_commit_author?: ?string,
     *     git_commit_at?: ?string,
     *     ssr_modules?: array<string, string>,
     *     ssr_entry_module?: string,
     *     middleware_modules?: array<string, string>,
     *     middleware_entry_module?: string,
     *     middleware_source_path?: string
     * }
     */
    public function build(
        EdgeDeployment $deployment,
        string $repoUrl,
        string $branch,
        string $buildCommand,
        string $outputDir,
        array $env = [],
        ?string $commitOverride = null,
        string $runtimeMode = self::MODE_STATIC,
        ?string $repoRoot = null,
    ): array {
        $workRoot = rtrim(sys_get_temp_dir(), '/').'/dply-edge-build-'.$deployment->id;
        File::ensureDirectoryExists($workRoot);
        $checkout = $workRoot.'/src';
        $artifactDir = $workRoot.'/out';
        $buildLog = $workRoot.'/build.log';
        File::put($buildLog, '=== dply Edge build '.$deployment->id." ===\n");

        try {
            if (FakeEdgeProvision::enabled()) {
                File::ensureDirectoryExists($artifactDir);
                File::put($artifactDir.'/index.html', '<!doctype html><html><body><h1>dply Edge fake build</h1></body></html>');
                $this->appendBuildLog($buildLog, "Fake edge build — skipped git clone and Docker.\n");
                $fakeCommit = $commitOverride !== null
                    ? strtolower($commitOverride)
                    : substr(hash('sha1', $deployment->id), 0, 40);

                $result = [
                    'artifact_dir' => $artifactDir,
                    'build_log' => $buildLog,
                    'git_commit' => $fakeCommit,
                ];

                if ($runtimeMode === self::MODE_SSR) {
                    // Stand-in worker module so the fake-edge path covers
                    // the SSR upload code (publisher reads ssr_modules
                    // verbatim and ships it to the dispatch namespace).
                    $result['ssr_entry_module'] = 'worker.js';
                    $result['ssr_modules'] = [
                        'worker.js' => "export default {\n  async fetch(request) {\n    return new Response('dply Edge fake SSR build', { headers: { 'content-type': 'text/plain' } });\n  },\n};\n",
                    ];
                }

                return $result;
            }

            $this->assertDockerAvailable();

            if ($commitOverride !== null) {
                $this->appendBuildLog($buildLog, "Cloning {$repoUrl} (full history) for commit {$commitOverride}\n");
                $clone = Process::timeout(300)->run(['git', 'clone', $repoUrl, $checkout]);
                $this->appendBuildLog($buildLog, $clone->output().$clone->errorOutput());
                if (! $clone->successful()) {
                    throw new RuntimeException('Git clone failed: '.$clone->errorOutput());
                }
                $checkoutResult = Process::timeout(60)->path($checkout)->run(['git', 'checkout', $commitOverride]);
                $this->appendBuildLog($buildLog, $checkoutResult->output().$checkoutResult->errorOutput());
                if (! $checkoutResult->successful()) {
                    throw new RuntimeException('Build failed: commit "'.$commitOverride.'" not found in repository.');
                }
            } else {
                $this->appendBuildLog($buildLog, "Cloning {$repoUrl} @ {$branch}\n");
                $clone = Process::timeout(300)->run([
                    'git', 'clone', '--depth', '1', '--branch', $branch, $repoUrl, $checkout,
                ]);
                $this->appendBuildLog($buildLog, $clone->output().$clone->errorOutput());
                if (! $clone->successful()) {
                    throw new RuntimeException('Git clone failed: '.$clone->errorOutput());
                }
            }

            $resolvedCommit = $this->resolveHead($checkout);
            $commitDetails = $this->resolveHeadDetails($checkout);

            $checkout = EdgeRepoRoot::applyToCheckout(
                $checkout,
                $repoRoot,
                fn (string $line) => $this->appendBuildLog($buildLog, $line."\n"),
            );

            // Read dply.yaml (if present) from the checkout root before
            // running the build so build overrides + redirects/headers
            // ship in the same deploy. Snapshot persists on the
            // deployment row so the worker payload can read it later.
            $repoConfig = app(EdgeRepoConfigLoader::class)->loadFromDirectory($checkout);
            $lint = app(EdgeRepoConfigLinter::class)->lint($repoConfig);
            $this->appendBuildLog($buildLog, 'Config lint: '.($lint['ok'] ? 'ok' : 'FAILED')."\n");
            foreach ($lint['warnings'] as $warning) {
                $this->appendBuildLog($buildLog, '[dply.yaml] '.$warning."\n");
            }
            foreach ($lint['errors'] as $error) {
                $this->appendBuildLog($buildLog, '[dply.yaml] ERROR: '.$error."\n");
            }
            if (! $lint['ok']) {
                throw new RuntimeException(
                    'dply config lint failed: '.implode('; ', $lint['errors']),
                );
            }

            if ($repoConfig !== null) {
                $this->appendBuildLog($buildLog, sprintf(
                    "Loaded %s — build:%s redirects:%d rewrites:%d headers:%d\n",
                    $repoConfig->sourcePath,
                    $repoConfig->build === [] ? ' (defaults)' : ' '.json_encode($repoConfig->build, JSON_THROW_ON_ERROR),
                    count($repoConfig->redirects),
                    count($repoConfig->rewrites),
                    count($repoConfig->headers),
                ));
                $deployment->update(['repo_config' => $repoConfig->toArray()]);

                if (isset($repoConfig->build['root'])) {
                    $candidate = $checkout.'/'.trim($repoConfig->build['root'], '/');
                    if (is_dir($candidate)) {
                        $checkout = $candidate;
                        $this->appendBuildLog($buildLog, 'Using build.root from dply.yaml: '.$repoConfig->build['root']."\n");
                    } else {
                        $this->appendBuildLog($buildLog, '[dply.yaml] build.root '.$repoConfig->build['root'].' not found; falling back to repo root.'."\n");
                    }
                }

                if (isset($repoConfig->build['command']) && $repoConfig->build['command'] !== '') {
                    $buildCommand = $repoConfig->build['command'];
                }
                if (isset($repoConfig->build['output']) && $repoConfig->build['output'] !== '') {
                    $outputDir = $repoConfig->build['output'];
                }

                // build.env_files: load each repo-relative dotenv file
                // and merge into $env. Dashboard-supplied env wins on
                // conflict (it's already in $env when we get here, and
                // we only set keys that aren't present).
                foreach ($repoConfig->envFiles as $relative) {
                    $path = $checkout.'/'.$relative;
                    if (! is_file($path)) {
                        $this->appendBuildLog($buildLog, '[dply.yaml] build.env_files: '.$relative." not found in checkout — skipping.\n");

                        continue;
                    }
                    $loaded = $this->parseDotenvFile((string) file_get_contents($path));
                    $added = 0;
                    foreach ($loaded as $k => $v) {
                        if (array_key_exists($k, $env)) {
                            // Dashboard / production-scope env wins.
                            continue;
                        }
                        $env[$k] = $v;
                        $added++;
                    }
                    $this->appendBuildLog($buildLog, sprintf("[dply.yaml] build.env_files: %s → %d new key(s)\n", $relative, $added));
                }
            }

            // SSR mode: detect which framework adapter the repo uses
            // and dispatch the build accordingly. Profile registry lives
            // in app/Services/Edge/Ssr/ — adding Astro / SvelteKit /
            // Remix / Next is a one-entry change there.
            $ssrProfile = null;
            if ($runtimeMode === self::MODE_SSR) {
                if (! is_file($checkout.'/package.json')) {
                    throw new RuntimeException('SSR builds require a package.json declaring a supported framework + Cloudflare adapter.');
                }
                $packageJson = json_decode((string) file_get_contents($checkout.'/package.json'), true);
                $packageJson = is_array($packageJson) ? $packageJson : [];

                $ssrProfile = EdgeSsrFrameworkRegistry::detectInProject($packageJson);
                if ($ssrProfile === null) {
                    throw new RuntimeException(
                        'SSR Edge sites need one of: Next.js, Astro, SvelteKit, or Remix. '
                        .'Add the framework + its Cloudflare adapter to package.json or pick static / hybrid mode.'
                    );
                }
                if (! EdgeSsrFrameworkRegistry::adapterInstalled($ssrProfile, $packageJson)) {
                    throw new RuntimeException(sprintf(
                        '%s needs `%s` in package.json before SSR builds work — install the adapter and redeploy.',
                        $ssrProfile->label,
                        $ssrProfile->adapterDependency,
                    ));
                }

                $install = $this->detectInstallCommand($checkout) ?? 'npm install';
                if ($ssrProfile->buildCommandOverride !== null) {
                    $buildCommand = $install.' && '.$ssrProfile->buildCommandOverride;
                } else {
                    // Honor the user's build_command for adapters where
                    // they own the build (Astro / SvelteKit / Remix —
                    // they run `astro build` / `vite build` / `remix
                    // vite:build` themselves). Just ensure dependencies
                    // get installed first.
                    $needsInstall = ! preg_match('/\b(npm|yarn|pnpm|bun)\s+(ci|install)\b/i', $buildCommand);
                    if ($needsInstall) {
                        $buildCommand = $install.' && '.$buildCommand;
                    }
                }
                $outputDir = $ssrProfile->assetsPath;
                $this->appendBuildLog($buildLog, sprintf("SSR mode (%s) — adapter detected, build = %s\n", $ssrProfile->label, $buildCommand));
            }

            // Build cache restore — best-effort. Cache key is derived
            // from the lockfile contents in the resolved checkout so
            // monorepo subdirs + dply.yaml root overrides hash the
            // right file. Cache miss is silent; failures only print
            // to the build log and the deploy continues cold.
            $site = $deployment->site;
            $cacheKey = null;
            if ($site !== null) {
                try {
                    $cacheKey = app(EdgeBuildCache::class)->cacheKey($checkout, null);
                    $restoreResult = app(EdgeBuildCache::class)->restore($checkout, null, $cacheKey, $site);
                    $this->appendBuildLog(
                        $buildLog,
                        '[cache] '.$restoreResult['message'].' (key '.$cacheKey.')'."\n",
                    );
                } catch (\Throwable $e) {
                    $this->appendBuildLog($buildLog, '[cache] restore error: '.$e->getMessage()."\n");
                }
            }

            $dockerImage = (string) config('edge.build.docker_image', 'node:20-bookworm');
            $script = $this->composeBuildScript($checkout, $buildCommand);
            $this->appendBuildLog($buildLog, "Running build in {$dockerImage}: {$script}\n");
            $build = Process::timeout((int) config('edge.build.timeout_seconds', 900))
                ->run([
                    'docker', 'run', '--rm',
                    '-v', $checkout.':/src',
                    '-w', '/src',
                    ...$this->dockerEnvFlags($env),
                    $dockerImage,
                    'bash', '-lc', $script,
                ]);
            $this->appendBuildLog($buildLog, $build->output().$build->errorOutput());
            if (! $build->successful()) {
                throw new RuntimeException('Build failed: '.$build->errorOutput());
            }

            // Middleware bundling — runs in a separate docker
            // invocation against the same image so user-authored
            // middleware.ts can ship to the dispatch namespace without
            // requiring node + esbuild on the build host. Static + hybrid
            // sites only (SSR already owns middleware via OpenNext).
            $middlewareBundle = ['bundled' => false];
            if ($runtimeMode !== self::MODE_SSR) {
                try {
                    $middlewareBundle = app(EdgeMiddlewareBundler::class)->bundle(
                        $checkout,
                        $dockerImage,
                        fn (string $line) => $this->appendBuildLog($buildLog, $line."\n"),
                    );
                } catch (\Throwable $e) {
                    $this->appendBuildLog($buildLog, '[middleware] bundler error: '.$e->getMessage()."\n");
                    $middlewareBundle = ['bundled' => false];
                }
            }

            // Cache snapshot — runs inline after the Docker build
            // succeeds. ~5–30s on a typical Next.js build; acceptable
            // for v1, can move to a background queue later if it
            // becomes a deploy-time hotspot.
            if ($site !== null && $cacheKey !== null) {
                try {
                    $snapResult = app(EdgeBuildCache::class)->snapshot($checkout, null, $cacheKey, $site);
                    $this->appendBuildLog($buildLog, '[cache] '.$snapResult['message']."\n");
                    if ($snapResult['ok']) {
                        $deleted = app(EdgeBuildCache::class)->prune($site);
                        if ($deleted > 0) {
                            $this->appendBuildLog($buildLog, '[cache] pruned '.$deleted.' old cache entr(ies).'."\n");
                        }
                    }
                } catch (\Throwable $e) {
                    $this->appendBuildLog($buildLog, '[cache] snapshot error: '.$e->getMessage()."\n");
                }
            }

            $resolvedOutput = $checkout.'/'.trim($outputDir, '/');
            if (! is_dir($resolvedOutput)) {
                throw new RuntimeException("Build output directory not found: {$outputDir}");
            }

            File::ensureDirectoryExists($artifactDir);
            File::copyDirectory($resolvedOutput, $artifactDir);

            if ($runtimeMode === self::MODE_SSR && $ssrProfile !== null) {
                $workerPath = $checkout.'/'.$ssrProfile->workerPath;
                [$entryModule, $modules] = $this->collectSsrModules($workerPath, $ssrProfile->entryModule, $ssrProfile->label);
                $totalBytes = array_sum(array_map('strlen', $modules));
                $this->assertArtifactSize($artifactDir);
                $this->appendBuildLog($buildLog, sprintf(
                    "Build succeeded — %s assets + worker bundle ready (%d module(s), %d bytes).\n",
                    $ssrProfile->label,
                    count($modules),
                    $totalBytes,
                ));

                return [
                    'artifact_dir' => $artifactDir,
                    'build_log' => $buildLog,
                    'git_commit' => $resolvedCommit,
                    'git_commit_subject' => $commitDetails['subject'] ?? null,
                    'git_commit_author' => $commitDetails['author'] ?? null,
                    'git_commit_at' => $commitDetails['committed_at'] ?? null,
                    'ssr_entry_module' => $entryModule,
                    'ssr_modules' => $modules,
                ];
            }

            $this->assertArtifactContents($artifactDir, $outputDir);
            $this->assertArtifactSize($artifactDir);
            $this->appendBuildLog($buildLog, "Build succeeded — artifacts copied to output.\n");

            $result = [
                'artifact_dir' => $artifactDir,
                'build_log' => $buildLog,
                'git_commit' => $resolvedCommit,
                'git_commit_subject' => $commitDetails['subject'] ?? null,
                'git_commit_author' => $commitDetails['author'] ?? null,
                'git_commit_at' => $commitDetails['committed_at'] ?? null,
            ];

            if (($middlewareBundle['bundled'] ?? false) === true) {
                $result['middleware_modules'] = $middlewareBundle['modules'];
                $result['middleware_entry_module'] = (string) ($middlewareBundle['entry_module'] ?? 'middleware.js');
                $result['middleware_source_path'] = (string) ($middlewareBundle['source_path'] ?? '');
            }

            return $result;
        } finally {
            if (is_dir($checkout)) {
                File::deleteDirectory($checkout);
            }
        }
    }

    private function resolveHead(string $checkout): ?string
    {
        $result = Process::timeout(10)->path($checkout)->run(['git', 'rev-parse', 'HEAD']);
        if (! $result->successful()) {
            return null;
        }

        $sha = trim($result->output());

        return $sha === '' ? null : strtolower($sha);
    }

    /**
     * Capture commit subject + author + iso date for the resolved HEAD so the
     * preview row and deploy history can show what's actually deployed
     * (especially useful for ad-hoc previews from tags / non-main branches
     * where the SHA alone tells the operator nothing). Format uses %x1f
     * (unit separator) so commit messages with newlines/pipes don't trip us.
     *
     * @return array{subject: ?string, author: ?string, committed_at: ?string}
     */
    private function resolveHeadDetails(string $checkout): array
    {
        $result = Process::timeout(10)->path($checkout)->run([
            'git', 'log', '-1', '--pretty=format:%s%x1f%an%x1f%aI',
        ]);
        if (! $result->successful()) {
            return ['subject' => null, 'author' => null, 'committed_at' => null];
        }

        $parts = explode("\x1f", trim($result->output()), 3);

        return [
            'subject' => isset($parts[0]) && $parts[0] !== '' ? mb_substr($parts[0], 0, 200) : null,
            'author' => isset($parts[1]) && $parts[1] !== '' ? mb_substr($parts[1], 0, 120) : null,
            'committed_at' => $parts[2] ?? null,
        ];
    }

    private function appendBuildLog(string $path, string $chunk): void
    {
        File::append($path, $chunk);
    }

    /**
     * Minimal dotenv parser for build.env_files. Honors KEY=value,
     * double-quoted values (\n + \t escapes), single-quoted (literal),
     * # comments, blank lines, and `export KEY=value`. No interpolation.
     *
     * @return array<string, string>
     */
    private function parseDotenvFile(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eq = strpos($trimmed, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eq));
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                continue;
            }
            $value = trim(substr($trimmed, $eq + 1));
            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = str_replace(['\\n', '\\t', '\\"'], ["\n", "\t", '"'], substr($value, 1, -1));
            } elseif (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
                $value = substr($value, 1, -1);
            } else {
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Read the SSR adapter's worker output into the module map
     * {@see EdgeSsrBundleUploader} ships to the dispatch namespace.
     * Accepts either a single bundled file (Next.js / Remix style) or
     * a directory of helper modules + entry (Astro / SvelteKit style).
     *
     * @return array{0: string, 1: array<string, string>} [entryModule, modules]
     */
    private function collectSsrModules(string $workerPath, string $configuredEntry, string $frameworkLabel): array
    {
        if (is_dir($workerPath)) {
            $modules = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($workerPath, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, ['js', 'mjs', 'wasm'], true)) {
                    continue;
                }
                $relative = ltrim(str_replace($workerPath, '', $file->getPathname()), '/\\');
                $modules[$relative] = (string) file_get_contents($file->getPathname());
            }
            if (! isset($modules[$configuredEntry])) {
                throw new RuntimeException(sprintf(
                    '%s build wrote %s/ but %s is missing — check the build log for adapter errors.',
                    $frameworkLabel,
                    $workerPath,
                    $configuredEntry,
                ));
            }
            $totalBytes = array_sum(array_map('strlen', $modules));
            if ($totalBytes > self::SSR_SCRIPT_MAX_BYTES) {
                throw new RuntimeException(sprintf(
                    'SSR worker bundle (%s, %d modules) exceeds the per-script size limit (%s bytes).',
                    $frameworkLabel,
                    count($modules),
                    number_format(self::SSR_SCRIPT_MAX_BYTES),
                ));
            }

            return [$configuredEntry, $modules];
        }

        if (! is_file($workerPath)) {
            throw new RuntimeException(sprintf(
                '%s build did not produce %s — check the build log for adapter errors.',
                $frameworkLabel,
                $workerPath,
            ));
        }
        $bytes = filesize($workerPath);
        if ($bytes === false || $bytes > self::SSR_SCRIPT_MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'SSR worker script (%s) exceeds the per-script size limit (%s bytes).',
                $frameworkLabel,
                number_format(self::SSR_SCRIPT_MAX_BYTES),
            ));
        }
        $singleName = basename($workerPath);

        return [$singleName, [$singleName => (string) file_get_contents($workerPath)]];
    }

    private function composeBuildScript(string $checkout, string $buildCommand): string
    {
        $install = $this->detectInstallCommand($checkout);
        if ($install === null) {
            return $buildCommand;
        }

        $needle = strtolower($buildCommand);
        if (str_contains($needle, 'npm ci') || str_contains($needle, 'npm install')
            || str_contains($needle, 'pnpm install') || str_contains($needle, 'yarn install')
            || str_contains($needle, 'bun install')) {
            return $buildCommand;
        }

        return $install.' && '.$buildCommand;
    }

    private function detectInstallCommand(string $checkout): ?string
    {
        if (is_file($checkout.'/pnpm-lock.yaml')) {
            return 'corepack enable && pnpm install --frozen-lockfile';
        }
        if (is_file($checkout.'/yarn.lock')) {
            return 'corepack enable && yarn install --frozen-lockfile';
        }
        if (is_file($checkout.'/bun.lockb') || is_file($checkout.'/bun.lock')) {
            return 'npm install -g bun && bun install --frozen-lockfile';
        }
        if (is_file($checkout.'/package-lock.json')) {
            return 'npm ci';
        }
        if (is_file($checkout.'/package.json')) {
            return 'npm install';
        }

        return null;
    }

    private function assertDockerAvailable(): void
    {
        $probe = Process::timeout(10)->run(['docker', 'version', '--format', '{{.Server.Version}}']);
        if ($probe->successful()) {
            return;
        }

        $hint = app()->environment('local')
            ? 'Start OrbStack/Docker Desktop locally, or set DPLY_FAKE_EDGE=true in .env to skip real builds during development.'
            : 'Install Docker on this build worker (e.g. `curl -fsSL https://get.docker.com | sh && systemctl enable --now docker`). The runtime path still serves from Cloudflare; Docker only sandboxes the customer build.';

        throw new RuntimeException('Edge build requires Docker but the daemon is not reachable. '.$hint);
    }

    private function assertArtifactContents(string $dir, string $outputDir): void
    {
        $hasFile = false;
        $hasIndex = false;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $hasFile = true;
            $relative = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
            if ($relative === 'index.html') {
                $hasIndex = true;
                break;
            }
        }
        if (! $hasFile) {
            throw new RuntimeException("Build produced no files in output directory: {$outputDir}");
        }
        if (! $hasIndex) {
            throw new RuntimeException("Build output is missing index.html at the root of: {$outputDir}");
        }
    }

    private function assertArtifactSize(string $dir): void
    {
        $max = (int) config('edge.build.artifact_max_bytes', 524_288_000);
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
                if ($total > $max) {
                    throw new RuntimeException('Build artifacts exceed maximum allowed size.');
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $env
     * @return list<string>
     */
    private function dockerEnvFlags(array $env): array
    {
        $flags = [];
        foreach ($env as $key => $value) {
            if ($key === '') {
                continue;
            }
            $flags[] = '-e';
            $flags[] = $key.'='.$value;
        }

        return $flags;
    }
}
