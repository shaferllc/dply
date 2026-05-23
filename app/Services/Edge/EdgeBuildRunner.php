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

            $clone = Process::timeout(300)->run([
                'git', 'clone', '--depth', '1', '--branch', $branch, $repoUrl, $checkout,
            ]);
            if (! $clone->successful()) {
                throw new RuntimeException('Git clone failed: '.$clone->errorOutput());
            }

            $dockerImage = (string) config('edge.build.docker_image', 'node:20-bookworm');
            $build = Process::timeout((int) config('edge.build.timeout_seconds', 900))
                ->run([
                    'docker', 'run', '--rm',
                    '-v', $checkout.':/src',
                    '-w', '/src',
                    ...$this->dockerEnvFlags($env),
                    $dockerImage,
                    'bash', '-lc', $buildCommand,
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
            $this->assertArtifactSize($artifactDir);

            return $artifactDir;
        } finally {
            if (is_dir($checkout)) {
                File::deleteDirectory($checkout);
            }
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
