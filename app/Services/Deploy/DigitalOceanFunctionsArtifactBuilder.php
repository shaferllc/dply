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
        private readonly BrefInjector $brefInjector,
        private readonly DigitalOceanFunctionsLaravelAdapter $laravelAdapter,
        private readonly ServerlessDeployProgress $progress,
    ) {}

    /**
     * @return array{artifact_path: string, output: string}
     */
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
        );
        $this->progress->done($site, 'checkout', 'Cloned repository');

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

        if ($buildCommand === '') {
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

        $log = array_filter([$checkout['output'], ...$brefLog, ...$laravelAdapterLog]);

        $this->progress->active($site, 'dependencies', 'Installing dependencies', $buildCommand);
        $log[] = $this->runShell($buildCommand, $checkout['working_directory']);
        $this->progress->done($site, 'dependencies', 'Installed dependencies');

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
            $this->zipPath($sourcePath, $artifactPath);
        }
        $this->progress->done($site, 'package', 'Packaged artifact');

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
            throw new \RuntimeException('Unable to create serverless artifact zip.');
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
