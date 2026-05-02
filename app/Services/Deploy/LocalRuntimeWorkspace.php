<?php

namespace App\Services\Deploy;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class LocalRuntimeWorkspace
{
    /**
     * @return array{workspace_path: string, repository_path: string, branch: string, revision: ?string}
     */
    public function ensure(Site $site): array
    {
        $workspacePath = storage_path('app/local-runtimes/'.$site->getKey());
        $repositoryPath = $workspacePath.'/repo';
        $workingDirectory = $repositoryPath;
        $subdirectory = $site->runtimeRepositorySubdirectory();
        $branch = trim((string) ($site->git_branch ?: 'main'));

        File::ensureDirectoryExists($workspacePath);

        if (! File::isDirectory($repositoryPath.'/.git')) {
            File::deleteDirectory($repositoryPath);

            $this->run([
                'git',
                'clone',
                '--branch',
                $branch,
                '--single-branch',
                (string) $site->git_repository_url,
                $repositoryPath,
            ], $workspacePath);
        } else {
            $this->run(['git', 'fetch', '--all', '--prune'], $repositoryPath);
            $this->run(['git', 'checkout', $branch], $repositoryPath);
            $this->run(['git', 'pull', '--ff-only', 'origin', $branch], $repositoryPath);
        }

        $revision = trim($this->run(['git', 'rev-parse', 'HEAD'], $repositoryPath));

        if ($subdirectory !== '') {
            $workingDirectory .= '/'.$subdirectory;
        }

        if (! File::isDirectory($workingDirectory)) {
            throw new \RuntimeException('Local runtime repository subdirectory does not exist: '.$subdirectory);
        }

        return [
            'workspace_path' => $workspacePath,
            'repository_path' => $repositoryPath,
            'working_directory' => $workingDirectory,
            'branch' => $branch,
            'revision' => $revision !== '' ? $revision : null,
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $workingDirectory): string
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout((int) config('sites.local_runtime_git_timeout_seconds', 900));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new \RuntimeException($this->timedOutMessage($process, $command), previous: $e);
        }

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Local runtime workspace command failed.');
        }

        return trim($process->getOutput());
    }

    /**
     * @param  list<string>  $command
     */
    private function timedOutMessage(Process $process, array $command): string
    {
        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        return trim(implode("\n\n", array_filter([
            'Local runtime workspace command timed out after '.(int) $process->getTimeout().' seconds.',
            'Command: '.$commandString,
            $output !== '' ? "Partial output:\n".$output : null,
        ])));
    }
}
