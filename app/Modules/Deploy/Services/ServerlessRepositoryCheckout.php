<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\User;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Support\GitCloneUrl;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final class ServerlessRepositoryCheckout
{
    public function __construct(
        private readonly SourceControlRepositoryBrowser $repositoryBrowser,
        private readonly GitIdentityResolver $resolver = new GitIdentityResolver,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onOutput
     * @return array{
     *     workspace_path: string,
     *     repository_path: string,
     *     working_directory: string,
     *     output: string,
     *     branch: string
     * }
     */
    public function checkout(
        string $workspaceKey,
        string $repositoryUrl,
        string $branch,
        string $subdirectory = '',
        int|string|null $userId = null,
        ?string $sourceControlAccountId = null,
        ?string $refKind = null,
        ?callable $onOutput = null,
    ): array {
        $repositoryUrl = trim($repositoryUrl);
        $branch = trim($branch) !== '' ? trim($branch) : 'main';
        $subdirectory = trim($subdirectory, '/');
        $refKind = in_array($refKind, ['branch', 'tag', 'commit'], true) ? $refKind : 'branch';
        $isCommit = $refKind === 'commit';

        if ($repositoryUrl === '') {
            throw new \RuntimeException('Choose a repository before continuing.');
        }

        // Every serverless clone funnels through here — the create wizard's
        // preview detection, the deploy build, and the API create — so this is
        // the one place the target has to be checked. A clone is an outbound
        // fetch this host performs against a caller-supplied URL, and its
        // stderr comes back to the caller in the deploy log.
        GitCloneUrl::assertClonable(
            $repositoryUrl,
            (array) config('serverless.git_clone.allowed_hosts', []),
            (bool) config('serverless.git_clone.allow_local_paths', false),
        );

        $workspacePath = storage_path('app/serverless-repositories/'.$workspaceKey);
        $repositoryPath = $workspacePath.'/repo';
        File::ensureDirectoryExists($workspacePath);

        $cloneUrl = $this->cloneUrl($repositoryUrl, $userId, $sourceControlAccountId);
        $log = [];
        $resolvedBranch = $branch;

        if (is_dir($repositoryPath.'/.git')) {
            $log[] = $this->run(['git', '-C', $repositoryPath, 'remote', 'set-url', 'origin', $cloneUrl], $workspacePath, $onOutput);
            if ($isCommit) {
                // Commit SHAs: fetch full history then detached checkout.
                $log[] = $this->run(['git', '-C', $repositoryPath, 'fetch', '--all', '--prune'], $workspacePath, $onOutput);
                $log[] = $this->run(['git', '-C', $repositoryPath, 'checkout', '--detach', $branch], $workspacePath, $onOutput);
                $log[] = $this->run(['git', '-C', $repositoryPath, 'clean', '-fdx'], $workspacePath, $onOutput);
            } else {
                $resolvedBranch = $this->fetchBranch($repositoryPath, $workspacePath, $cloneUrl, $branch, $log, $onOutput);
                $log[] = $this->run(['git', '-C', $repositoryPath, 'checkout', '-B', $resolvedBranch, 'FETCH_HEAD'], $workspacePath, $onOutput);
                $log[] = $this->run(['git', '-C', $repositoryPath, 'clean', '-fdx'], $workspacePath, $onOutput);
            }
        } else {
            File::deleteDirectory($repositoryPath);
            if ($isCommit) {
                // Full clone (no --depth, no --branch) so any commit is reachable.
                $log[] = $this->run(['git', 'clone', $cloneUrl, $repositoryPath], $workspacePath, $onOutput);
                $log[] = $this->run(['git', '-C', $repositoryPath, 'checkout', '--detach', $branch], $workspacePath, $onOutput);
            } else {
                try {
                    $log[] = $this->run(['git', 'clone', '--depth', '1', '--branch', $branch, $cloneUrl, $repositoryPath], $workspacePath, $onOutput);
                } catch (\RuntimeException $e) {
                    $fallbackBranch = $this->defaultBranchForCloneUrl($cloneUrl, $workspacePath);
                    if ($fallbackBranch === null || $fallbackBranch === $branch) {
                        throw $e;
                    }

                    $resolvedBranch = $fallbackBranch;
                    $log[] = sprintf('Requested branch "%s" was unavailable. Falling back to remote default branch "%s".', $branch, $fallbackBranch);
                    File::deleteDirectory($repositoryPath);
                    $log[] = $this->run(['git', 'clone', '--depth', '1', '--branch', $fallbackBranch, $cloneUrl, $repositoryPath], $workspacePath, $onOutput);
                }
            }
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
            'branch' => $resolvedBranch,
        ];
    }

    public function cleanup(string $workspacePath): void
    {
        if ($workspacePath !== '') {
            File::deleteDirectory($workspacePath);
        }
    }

    private function cloneUrl(string $repositoryUrl, int|string|null $userId, ?string $sourceControlAccountId): string
    {
        // Expand bare "owner/name" shorthand (serverless demos) before any
        // auth rewrite — authenticatedCloneUrl only injects credentials into
        // already-URL-shaped remotes.
        $repositoryUrl = GitCloneUrl::normalize($repositoryUrl);

        $accountId = is_string($sourceControlAccountId) ? trim($sourceControlAccountId) : '';
        if ($accountId === '' || $userId === null) {
            return $repositoryUrl;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            throw new \RuntimeException('The selected source-control account could not be found.');
        }

        $identity = $this->resolver->forId($user, $accountId);
        if ($identity === null) {
            throw new \RuntimeException('The selected source-control account could not be found.');
        }

        return $this->repositoryBrowser->authenticatedCloneUrl($identity, $repositoryUrl);
    }

    /**
     * @param  array<int, string>  $command
     * @param  (callable(string): void)|null  $onOutput
     */
    private function run(array $command, string $workingDirectory, ?callable $onOutput = null): string
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(300);
        $captured = '';
        $process->run(function (string $type, string $buffer) use ($onOutput, &$captured): void {
            $captured .= $buffer;
            if ($onOutput !== null && $buffer !== '') {
                $onOutput($buffer);
            }
        });

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()."\n".$process->getOutput()));
        }

        return trim($captured !== '' ? $captured : $process->getOutput());
    }

    /**
     * @param  list<string>  $log
     * @param  (callable(string): void)|null  $onOutput
     */
    private function fetchBranch(
        string $repositoryPath,
        string $workspacePath,
        string $cloneUrl,
        string $branch,
        array &$log,
        ?callable $onOutput = null,
    ): string {
        try {
            $log[] = $this->run(['git', '-C', $repositoryPath, 'fetch', '--depth', '1', 'origin', $branch], $workspacePath, $onOutput);

            return $branch;
        } catch (\RuntimeException $e) {
            $fallbackBranch = $this->defaultBranchForCloneUrl($cloneUrl, $workspacePath);
            if ($fallbackBranch === null || $fallbackBranch === $branch) {
                throw $e;
            }

            $log[] = sprintf('Requested branch "%s" was unavailable. Falling back to remote default branch "%s".', $branch, $fallbackBranch);
            $log[] = $this->run(['git', '-C', $repositoryPath, 'fetch', '--depth', '1', 'origin', $fallbackBranch], $workspacePath, $onOutput);

            return $fallbackBranch;
        }
    }

    private function defaultBranchForCloneUrl(string $cloneUrl, string $workspacePath): ?string
    {
        $output = $this->run(['git', 'ls-remote', '--symref', $cloneUrl, 'HEAD'], $workspacePath);

        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            if (preg_match('#^ref:\s+refs/heads/([^\s]+)\s+HEAD$#', trim($line), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
