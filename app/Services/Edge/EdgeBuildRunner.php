<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class EdgeBuildRunner
{
    /**
     * @param  array<string, string>  $env
     */
    public function build(
        EdgeDeployment $deployment,
        string $repoUrl,
        string $branch,
        string $buildCommand,
        string $outputDir,
        array $env = [],
    ): string {
        $workRoot = rtrim(sys_get_temp_dir(), '/').'/dply-edge-build-'.$deployment->id;
        File::ensureDirectoryExists($workRoot);
        $checkout = $workRoot.'/src';
        $artifactDir = $workRoot.'/out';

        try {
            if (FakeEdgeProvision::enabled()) {
                File::ensureDirectoryExists($artifactDir);
                File::put($artifactDir.'/index.html', '<!doctype html><html><body><h1>dply Edge fake build</h1></body></html>');

                return $artifactDir;
            }

            $this->assertDockerAvailable();

            $clone = Process::timeout(300)->run([
                'git', 'clone', '--depth', '1', '--branch', $branch, $repoUrl, $checkout,
            ]);
            if (! $clone->successful()) {
                throw new RuntimeException('Git clone failed: '.$clone->errorOutput());
            }

            $dockerImage = (string) config('edge.build.docker_image', 'node:20-bookworm');
            $script = $this->composeBuildScript($checkout, $buildCommand);
            $build = Process::timeout((int) config('edge.build.timeout_seconds', 900))
                ->run([
                    'docker', 'run', '--rm',
                    '-v', $checkout.':/src',
                    '-w', '/src',
                    ...$this->dockerEnvFlags($env),
                    $dockerImage,
                    'bash', '-lc', $script,
                ]);
            if (! $build->successful()) {
                throw new RuntimeException('Build failed: '.$build->errorOutput());
            }

            $resolvedOutput = $checkout.'/'.trim($outputDir, '/');
            if (! is_dir($resolvedOutput)) {
                throw new RuntimeException("Build output directory not found: {$outputDir}");
            }

            File::ensureDirectoryExists($artifactDir);
            File::copyDirectory($resolvedOutput, $artifactDir);
            $this->assertArtifactContents($artifactDir, $outputDir);
            $this->assertArtifactSize($artifactDir);

            return $artifactDir;
        } finally {
            if (is_dir($checkout)) {
                File::deleteDirectory($checkout);
            }
        }
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
