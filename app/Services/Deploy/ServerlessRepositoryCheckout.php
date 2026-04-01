<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\SocialAccount;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final class ServerlessRepositoryCheckout
{
    public function __construct(
        private readonly SourceControlRepositoryBrowser $repositoryBrowser,
    ) {}

    /**
     * @return array{
     *     workspace_path: string,
     *     repository_path: string,
     *     working_directory: string,
     *     output: string
     * }
     */
    public function checkout(
        string $workspaceKey,
        string $repositoryUrl,
        string $branch,
        string $subdirectory = '',
        ?int $userId = null,
        ?string $sourceControlAccountId = null,
    ): array {
        $repositoryUrl = trim($repositoryUrl);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';
        $subdirectory = trim($subdirectory, '/');

        if ($repositoryUrl === '') {
            throw new \RuntimeException('Choose a repository before continuing.');
        }

        $workspacePath = storage_path('app/serverless-repositories/'.$workspaceKey);
        $repositoryPath = $workspacePath.'/repo';
        File::ensureDirectoryExists($workspacePath);

        $cloneUrl = $this->cloneUrl($repositoryUrl, $userId, $sourceControlAccountId);
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

        $workingDirectory = $repositoryPath;
        if ($subdirectory !== '') {
            $workingDirectory .= '/'.$subdirectory;
        }

        if (! is_dir($workingDirectory)) {
            throw new \RuntimeException('Functions repository subdirectory does not exist: '.$subdirectory);
        }

        return [
            'workspace_path' => $workspacePath,
            'repository_path' => $repositoryPath,
            'working_directory' => $workingDirectory,
            'output' => trim(implode("\n", array_filter($log))),
        ];
    }

    public function cleanup(string $workspacePath): void
    {
        if ($workspacePath !== '') {
            File::deleteDirectory($workspacePath);
        }
    }

    private function cloneUrl(string $repositoryUrl, ?int $userId, ?string $sourceControlAccountId): string
    {
        $accountId = is_string($sourceControlAccountId) ? trim($sourceControlAccountId) : '';
        if ($accountId === '' || $userId === null) {
            return $repositoryUrl;
        }

        $account = SocialAccount::query()
            ->where('user_id', $userId)
            ->find($accountId);

        if (! $account) {
            throw new \RuntimeException('The selected source-control account could not be found.');
        }

        return $this->repositoryBrowser->authenticatedCloneUrl($account, $repositoryUrl);
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
}
