<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use Symfony\Component\Process\Process;

/**
 * Reads commit history for the AI roadmap updater — the truest signal of what
 * actually shipped. Works in two environments:
 *
 *   - Local dev: a normal checkout, read via `git -C <base_path>`.
 *   - Deployed box (atomic releases): the live code is a `git archive` with no
 *     `.git`, but a bare repo sits at `<root>/repo` (two levels above
 *     base_path). We read that via `git --git-dir`.
 *
 * An explicit `config('roadmap.ai.git_dir')` overrides the auto-detection.
 * Returns an empty list (never throws) when no git is reachable, so the updater
 * degrades to docs/suggestions/items-only input rather than failing a deploy.
 */
final class RoadmapGitHistory
{
    /** Record separator (RS) between commits, unit separator (US) between fields. */
    private const RS = "\x1e";

    private const US = "\x1f";

    /**
     * Commits reachable from $toCommit but not from $fromCommit, newest first.
     * When $fromCommit is null (first run) returns the most recent
     * `roadmap.ai.max_commits` commits up to $toCommit (or HEAD).
     *
     * @return list<array{sha: string, subject: string, body: string, date: string}>
     */
    public function commitsSince(?string $fromCommit, ?string $toCommit = null): array
    {
        $gitArgs = $this->gitLocatorArgs();
        if ($gitArgs === null) {
            return [];
        }

        $to = $this->sanitizeRef($toCommit) ?? 'HEAD';
        $from = $this->sanitizeRef($fromCommit);
        $max = max(1, (int) config('roadmap.ai.max_commits', 200));

        $range = $from !== null ? $from.'..'.$to : $to;

        $args = array_merge($gitArgs, [
            'log',
            '--no-merges',
            '--max-count='.$max,
            '--date=short',
            '--pretty=format:%H'.self::US.'%s'.self::US.'%cd'.self::US.'%b'.self::RS,
            $range,
        ]);

        $process = new Process(array_merge(['git'], $args), base_path());
        $process->setTimeout(20);

        try {
            $process->run();
        } catch (\Throwable) {
            return [];
        }

        if (! $process->isSuccessful()) {
            // An invalid `from..to` range (e.g. cursor SHA no longer present after
            // a force-push) shouldn't be fatal — fall back to the last N commits.
            if ($from !== null) {
                return $this->commitsSince(null, $toCommit);
            }

            return [];
        }

        return $this->parse($process->getOutput());
    }

    /** Current HEAD SHA for the detected repo, or null if unreadable. */
    public function headCommit(): ?string
    {
        $gitArgs = $this->gitLocatorArgs();
        if ($gitArgs === null) {
            return null;
        }

        $process = new Process(array_merge(['git'], $gitArgs, ['rev-parse', 'HEAD']), base_path());
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        $sha = trim($process->getOutput());

        return ($process->isSuccessful() && $sha !== '') ? $sha : null;
    }

    /**
     * The `git` locator flags (`-C <dir>` or `--git-dir <dir>`) for the repo we
     * should read, or null when none is reachable.
     *
     * @return list<string>|null
     */
    private function gitLocatorArgs(): ?array
    {
        $configured = config('roadmap.ai.git_dir');
        if (is_string($configured) && $configured !== '' && is_dir($configured)) {
            return ['--git-dir', $configured];
        }

        if (is_dir(base_path('.git'))) {
            return ['-C', base_path()];
        }

        // Atomic-release layout: base_path() == <root>/releases/<ts>; the bare
        // repo is <root>/repo.
        $bare = dirname(base_path(), 2).'/repo';
        if (is_dir($bare)) {
            return ['--git-dir', $bare];
        }

        return null;
    }

    /** Only allow SHA / ref-shaped tokens through to the git command line. */
    private function sanitizeRef(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }
        $ref = trim($ref);

        return preg_match('/^[A-Za-z0-9._\/-]{1,128}$/', $ref) === 1 ? $ref : null;
    }

    /**
     * @return list<array{sha: string, subject: string, body: string, date: string}>
     */
    private function parse(string $output): array
    {
        $commits = [];
        foreach (explode(self::RS, $output) as $record) {
            $record = trim($record, "\n\r");
            if ($record === '') {
                continue;
            }
            $fields = explode(self::US, $record);
            if (count($fields) < 3) {
                continue;
            }
            $sha = trim($fields[0]);
            if ($sha === '') {
                continue;
            }
            $commits[] = [
                'sha' => $sha,
                'subject' => trim($fields[1]),
                'date' => trim($fields[2]),
                'body' => trim($fields[3] ?? ''),
            ];
        }

        return $commits;
    }
}
