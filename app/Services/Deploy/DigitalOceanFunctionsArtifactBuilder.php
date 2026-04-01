<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Site;
use App\Models\SocialAccount;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use ZipArchive;

class DigitalOceanFunctionsArtifactBuilder
{
    public function __construct(
        private readonly SourceControlRepositoryBrowser $repositoryBrowser,
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
        $branch = trim((string) ($site->git_branch ?: 'main'));
        $subdirectory = trim((string) ($resolvedConfig['repository_subdirectory'] ?? ''));
        $buildCommand = trim((string) ($resolvedConfig['build_command'] ?? ''));
        $artifactOutputPath = trim((string) ($resolvedConfig['artifact_output_path'] ?? ''));

        if ($buildCommand === '') {
            throw new \RuntimeException('Set a build command before deploying this Functions site.');
        }

        if ($artifactOutputPath === '') {
            throw new \RuntimeException('Set a build output path before deploying this Functions site.');
        }

        $workspacePath = storage_path('app/functions-builds/'.$site->id);
        $repositoryPath = $workspacePath.'/repo';
        File::ensureDirectoryExists($workspacePath);

        $cloneUrl = $this->cloneUrl($site, $repositoryUrl, [
            'source_control_account_id' => $resolvedConfig['source_control_account_id'] ?? null,
        ]);
        $log = [];

        if (is_dir($repositoryPath.'/.git')) {
            $log[] = $this->run(['git', '-C', $repositoryPath, 'remote', 'set-url', 'origin', $cloneUrl], $workspacePath);
            $log[] = $this->run(['git', '-C', $repositoryPath, 'fetch', '--depth', '1', 'origin', $branch], $workspacePath);
            $log[] = $this->run(['git', '-C', $repositoryPath, 'checkout', '-B', $branch, 'FETCH_HEAD'], $workspacePath);
            $log[] = $this->run(['git', '-C', $repositoryPath, 'clean', '-fdx'], $workspacePath);
        } else {
            File::deleteDirectory($repositoryPath);
            $log[] = $this->run(['git', 'clone', '--depth', '1', '--branch', $branch, $cloneUrl, $repositoryPath], $workspacePath);
        }

        $buildWorkingDirectory = $repositoryPath;
        if ($subdirectory !== '') {
            $buildWorkingDirectory .= '/'.trim($subdirectory, '/');
        }

        if (! is_dir($buildWorkingDirectory)) {
            throw new \RuntimeException('Functions repository subdirectory does not exist: '.$subdirectory);
        }

        $log[] = $this->runShell($buildCommand, $buildWorkingDirectory);

        $sourcePath = $buildWorkingDirectory.'/'.ltrim($artifactOutputPath, '/');
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
            'output' => trim(implode("\n", array_filter($log))),
        ];
    }

    /**
     * @param  array<string, mixed>  $functionsConfig
     */
    private function cloneUrl(Site $site, string $repositoryUrl, array $functionsConfig): string
    {
        $accountId = trim((string) ($functionsConfig['source_control_account_id'] ?? ''));
        if ($accountId === '') {
            return $repositoryUrl;
        }

        $account = SocialAccount::query()
            ->where('user_id', $site->user_id)
            ->find($accountId);

        if (! $account) {
            throw new \RuntimeException('The selected source-control account could not be found for this site.');
        }

        return $this->repositoryBrowser->authenticatedCloneUrl($account, $repositoryUrl);
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
