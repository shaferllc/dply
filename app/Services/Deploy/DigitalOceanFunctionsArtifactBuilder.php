<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
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
    ) {}

    /**
     * @return array{artifact_path: string, output: string}
     */
    public function build(Site $site): array
    {
        $site->loadMissing('server');

        $repositoryUrl = trim((string) $site->git_repository_url);
        if ($repositoryUrl === '') {
            throw new \RuntimeException('Choose a repository before deploying this Functions site.');
        }

        $resolvedConfig = $this->deploymentConfigResolver->resolve($site);
        $checkout = $this->repositoryCheckout->checkout(
            'build-'.$site->id,
            $repositoryUrl,
            (string) ($site->git_branch ?: 'main'),
            (string) ($resolvedConfig['repository_subdirectory'] ?? ''),
            $site->user_id,
            isset($resolvedConfig['source_control_account_id']) && is_string($resolvedConfig['source_control_account_id'])
                ? $resolvedConfig['source_control_account_id']
                : null,
        );

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

        if ($buildCommand === '') {
            throw new \RuntimeException('Dply could not determine a build command for this Functions site. Open Advanced settings and set one manually.');
        }

        if ($artifactOutputPath === '') {
            throw new \RuntimeException('Dply could not determine a build output path for this Functions site. Open Advanced settings and set one manually.');
        }

        $resolvedConfig = $this->deploymentConfigResolver->persistResolvedConfig($site, [
            'runtime' => $runtime,
            'entrypoint' => $entrypoint,
            'package' => $package,
            'build_command' => $buildCommand,
            'artifact_output_path' => $artifactOutputPath,
        ]);

        $log = array_filter([$checkout['output']]);
        $log[] = $this->runShell($buildCommand, $checkout['working_directory']);

        $sourcePath = $checkout['working_directory'].'/'.ltrim($artifactOutputPath, '/');
        if (! file_exists($sourcePath)) {
            throw new \RuntimeException('Functions build output was not found at: '.$artifactOutputPath);
        }

        $artifactDirectory = storage_path('app/serverless-artifacts/'.$site->id);
        File::ensureDirectoryExists($artifactDirectory);
        $artifactPath = $artifactDirectory.'/'.$this->artifactFilename($site);

        if (is_file($sourcePath) && str_ends_with(strtolower($sourcePath), '.zip')) {
            File::copy($sourcePath, $artifactPath);
        } else {
            $this->zipPath($sourcePath, $artifactPath);
        }

        return [
            'artifact_path' => $artifactPath,
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

    private function artifactFilename(Site $site): string
    {
        $base = Str::slug($site->slug !== '' ? $site->slug : $site->name);
        $base = $base !== '' ? $base : 'site';

        return $base.'-'.now()->format('YmdHis').'.zip';
    }

    /**
     * @param  list<string>  $command
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

    private function zipPath(string $sourcePath, string $artifactPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($artifactPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Functions artifact zip.');
        }

        if (is_dir($sourcePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $localName = ltrim(str_replace($sourcePath, '', $item->getPathname()), DIRECTORY_SEPARATOR);
                if ($localName === '') {
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
