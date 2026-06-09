<?php

declare(strict_types=1);

namespace App\Services\Edge;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Wraps `git clone` with two protections operators care about:
 *
 *   • Mirror cache — one `--mirror` clone per repo URL, refreshed with
 *     `git fetch` instead of re-downloaded from scratch. The build then
 *     clones from the local mirror, so a flaky GitHub round-trip doesn't
 *     kill an otherwise-good deploy and a no-op rebuild is essentially
 *     network-free.
 *
 *   • Retry — every network-touching git call gets up to 3 tries with a
 *     short backoff, so a single TCP RTO timeout no longer fails the build.
 *
 * Falls back to direct clone (no cache, no retry-on-success) if the
 * mirror layer itself blows up, so caching can never be the reason a
 * build fails.
 */
final class EdgeRepoCloner
{
    private const NETWORK_RETRIES = 3;

    private const RETRY_BACKOFF_SECONDS = 2;

    /** Network ops can legitimately take a while on big repos. */
    private const NETWORK_TIMEOUT_SECONDS = 300;

    /**
     * Clone `$repoUrl` into `$checkout`, checking out `$branch` (and
     * optionally `$commitOverride`). Returns a list of log lines so the
     * caller can stream them into the build log.
     *
     * @return list<string>
     */
    public function clone(string $repoUrl, string $branch, string $checkout, ?string $commitOverride = null): array
    {
        $log = [];

        if ($this->cacheEnabled()) {
            try {
                return $this->cloneViaMirror($repoUrl, $branch, $checkout, $commitOverride, $log);
            } catch (\Throwable $e) {
                $log[] = '[git-cache] mirror path failed, falling back to direct clone: '.$e->getMessage();
                if (is_dir($checkout)) {
                    File::deleteDirectory($checkout);
                }
            }
        }

        return $this->cloneDirect($repoUrl, $branch, $checkout, $commitOverride, $log);
    }

    /**
     * @param  list<string>  $log
     * @return list<string>
     */
    private function cloneViaMirror(string $repoUrl, string $branch, string $checkout, ?string $commitOverride, array $log): array
    {
        $mirror = $this->mirrorPath($repoUrl);
        $cacheRoot = dirname($mirror);
        if (! is_dir($cacheRoot) && ! mkdir($cacheRoot, 0755, true) && ! is_dir($cacheRoot)) {
            throw new RuntimeException("Could not create git cache root: {$cacheRoot}");
        }

        // Serialize concurrent builds for the same repo so two workers
        // don't fight over the mirror dir.
        $lockHandle = $this->acquireLock($cacheRoot.'/.'.basename($mirror).'.lock');

        try {
            if (is_dir($mirror.'/objects')) {
                $log[] = "[git-cache] Refreshing mirror at {$mirror}";
                $this->runWithRetry(['git', '--git-dir='.$mirror, 'fetch', '--prune', '--tags', 'origin'], $log);
            } else {
                if (is_dir($mirror)) {
                    File::deleteDirectory($mirror);
                }
                $log[] = "[git-cache] Cloning mirror {$repoUrl} → {$mirror}";
                $this->runWithRetry(['git', 'clone', '--mirror', $repoUrl, $mirror], $log);
            }

            // The actual build checkout — clone from the local mirror.
            // git clones from a local path use hardlinks, so the working
            // tree is created near-instantly and `--depth` is a no-op
            // (git warns about it). Keep history; it costs almost nothing.
            $log[] = "Cloning from local mirror @ {$branch}";
            if ($commitOverride !== null) {
                $clone = Process::timeout(60)->run(['git', 'clone', $mirror, $checkout]);
            } else {
                $clone = Process::timeout(60)->run(['git', 'clone', '--branch', $branch, $mirror, $checkout]);
            }
            $log[] = trim($clone->output().$clone->errorOutput());
            if (! $clone->successful()) {
                throw new RuntimeException('Local mirror clone failed: '.$clone->errorOutput());
            }

            if ($commitOverride !== null) {
                $checkoutResult = Process::timeout(60)->path($checkout)->run(['git', 'checkout', $commitOverride]);
                $log[] = trim($checkoutResult->output().$checkoutResult->errorOutput());
                if (! $checkoutResult->successful()) {
                    throw new RuntimeException('Build failed: commit "'.$commitOverride.'" not found in repository.');
                }
            }
        } finally {
            $this->releaseLock($lockHandle);
        }

        return array_values(array_filter($log, static fn ($line) => $line !== ''));
    }

    /**
     * @param  list<string>  $log
     * @return list<string>
     */
    private function cloneDirect(string $repoUrl, string $branch, string $checkout, ?string $commitOverride, array $log): array
    {
        if ($commitOverride !== null) {
            $log[] = "Cloning {$repoUrl} (full history) for commit {$commitOverride}";
            $this->runWithRetry(['git', 'clone', $repoUrl, $checkout], $log);
            $result = Process::timeout(60)->path($checkout)->run(['git', 'checkout', $commitOverride]);
            $log[] = trim($result->output().$result->errorOutput());
            if (! $result->successful()) {
                throw new RuntimeException('Build failed: commit "'.$commitOverride.'" not found in repository.');
            }
        } else {
            $log[] = "Cloning {$repoUrl} @ {$branch}";
            $this->runWithRetry(['git', 'clone', '--depth', '1', '--branch', $branch, $repoUrl, $checkout], $log);
        }

        return array_values(array_filter($log, static fn ($line) => $line !== ''));
    }

    /**
     * Run a git command with bounded retry. Each failure logs a line so
     * operators can see WHY a retry kicked in (network blip vs. real auth
     * failure, etc.). Throws after the final attempt fails.
     *
     * @param  list<string>  $command
     * @param  list<string>  $log
     */
    private function runWithRetry(array $command, array &$log): void
    {
        $attempt = 0;
        $lastError = '';

        while ($attempt < self::NETWORK_RETRIES) {
            $attempt++;
            $result = Process::timeout(self::NETWORK_TIMEOUT_SECONDS)->run($command);
            $log[] = trim($result->output().$result->errorOutput());
            if ($result->successful()) {
                return;
            }

            $lastError = $result->errorOutput() !== '' ? $result->errorOutput() : $result->output();
            if ($attempt < self::NETWORK_RETRIES) {
                $log[] = sprintf('[git-cache] attempt %d/%d failed — retrying in %ds', $attempt, self::NETWORK_RETRIES, self::RETRY_BACKOFF_SECONDS);
                sleep(self::RETRY_BACKOFF_SECONDS);
            }
        }

        throw new RuntimeException('Git clone failed after '.self::NETWORK_RETRIES.' attempts: '.$lastError);
    }

    private function mirrorPath(string $repoUrl): string
    {
        $cacheRoot = (string) config(
            'edge.build.git_cache_dir',
            storage_path('app/edge-git-cache'),
        );

        // Hash the URL so we don't have to sanitize host/path/auth bits
        // into a directory name. Suffix with `.git` so a stray `git status`
        // on the parent doesn't try to treat the cache as a working tree.
        return rtrim($cacheRoot, '/').'/'.hash('sha256', strtolower(trim($repoUrl))).'.git';
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('edge.build.git_cache_enabled', true);
    }

    /**
     * @return resource|null
     */
    private function acquireLock(string $path)
    {
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param  resource|null  $handle
     */
    private function releaseLock($handle): void
    {
        if ($handle === null) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
