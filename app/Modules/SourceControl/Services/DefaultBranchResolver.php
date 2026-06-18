<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services;

use App\Modules\SourceControl\Contracts\GitIdentity;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Resolve a remote's default branch with `git ls-remote --symref HEAD`.
 *
 * Why this exists: the create flow used to default `branch = 'main'`,
 * either from the GitHub repo listing's `default_branch` field or from a
 * blind hardcode. That value is stale for repos whose default is `master`,
 * `12.x`, etc. — and a wrong branch makes the runtime-detection clone fail
 * with "Remote branch main not found in upstream origin". A live ls-remote
 * is authoritative for any provider, public or token-authed — but for
 * private repos the URL must carry the token, otherwise ls-remote returns
 * zero refs without error. That's why the SourceControlRepositoryBrowser
 * dependency is required, not optional: callers pass a {@see GitIdentity}
 * and we always route through {@see SourceControlRepositoryBrowser::authenticatedCloneUrl}.
 */
final class DefaultBranchResolver
{
    public function __construct(
        private readonly SourceControlRepositoryBrowser $browser,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * Probe $repositoryUrl for its default branch. Returns null when the
     * remote is unreachable, the ref can't be parsed, the repo is empty,
     * or git isn't on PATH — callers should fall back to whatever value
     * they already had.
     */
    public function resolve(string $repositoryUrl, ?GitIdentity $account = null): ?string
    {
        $repositoryUrl = trim($repositoryUrl);
        if ($repositoryUrl === '') {
            return null;
        }

        $url = $account !== null
            ? $this->browser->authenticatedCloneUrl($account, $repositoryUrl)
            : $repositoryUrl;

        $process = new Process(['git', 'ls-remote', '--symref', $url, 'HEAD']);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException|Throwable $e) {
            Log::debug('DefaultBranchResolver: ls-remote threw', [
                'url' => $repositoryUrl,
                'authed' => $account !== null,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::debug('DefaultBranchResolver: ls-remote non-zero', [
                'url' => $repositoryUrl,
                'authed' => $account !== null,
                'exit' => $process->getExitCode(),
                'stderr' => $this->sanitize($process->getErrorOutput(), $repositoryUrl),
            ]);

            return null;
        }

        // First stdout line for a symref'd HEAD looks like:
        //   ref: refs/heads/<branch>\tHEAD
        $stdout = $process->getOutput();
        if (preg_match('#^ref:\s+refs/heads/(\S+)\s+HEAD#m', $stdout, $m) === 1) {
            return $m[1];
        }

        Log::debug('DefaultBranchResolver: ls-remote produced no symref', [
            'url' => $repositoryUrl,
            'authed' => $account !== null,
            'stdout_len' => strlen($stdout),
        ]);

        return null;
    }

    private function sanitize(string $stderr, string $url): string
    {
        $sanitized = preg_replace('#(https?://)[^/@\s]*@#', '$1', $stderr);

        return trim($sanitized ?? $stderr);
    }
}
