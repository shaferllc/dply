<?php

declare(strict_types=1);

namespace App\Services\Deploy\RuntimeDetection;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Production {@see GitCloner} — shells out to `git` via Symfony Process.
 *
 * Uses `--depth=1 --single-branch` so we pull the minimum needed to
 * inspect repo signals at the tip of the requested branch. Full history
 * isn't useful for detection and adding it would multiply clone time on
 * large repos.
 */
final class ProcessGitCloner implements GitCloner
{
    /**
     * Default timeout for the clone process, in seconds. Overridable so
     * tests can drop it; the production value gives a reasonable budget
     * for a Render/Railway-shape large monorepo on a slow connection.
     */
    public function __construct(
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function shallowClone(string $url, string $branch, string $destination): void
    {
        if ($url === '') {
            throw new GitCloneException('Repository URL is required.');
        }
        if ($branch === '') {
            throw new GitCloneException('Branch is required.');
        }

        $process = new Process([
            'git',
            'clone',
            '--depth=1',
            '--single-branch',
            '--branch', $branch,
            $url,
            $destination,
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new GitCloneException(
                "Clone timed out after {$this->timeoutSeconds}s: {$url}",
                previous: $e,
            );
        }

        if (! $process->isSuccessful()) {
            $stderr = $this->sanitize($process->getErrorOutput(), $url);
            throw new GitCloneException(
                "Clone failed for {$this->displayUrl($url)} (branch {$branch}): {$stderr}",
            );
        }
    }

    /**
     * Strip credentials from a URL for safe display in error messages.
     * Handles git+https URLs of the form https://user:token@host/path.
     */
    private function displayUrl(string $url): string
    {
        return (string) preg_replace('#^(https?://)[^/@]*@#', '$1', $url);
    }

    /**
     * Mask the URL credentials in any captured stderr line. git tends to
     * echo the URL back in its error messages; we never want to surface
     * an embedded token to the UI or log.
     */
    private function sanitize(string $stderr, string $url): string
    {
        $sanitized = preg_replace('#(https?://)[^/@\s]*@#', '$1', $stderr);

        return trim((string) ($sanitized !== null ? $sanitized : $stderr));
    }
}
