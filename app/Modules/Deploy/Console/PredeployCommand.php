<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * dply:predeploy — run the pre-deploy AI generators (changelog + roadmap).
 *
 * Replaces the old githooks/pre-push generators. Run it by hand (or from a
 * deploy script) before shipping a set of commits:
 *
 *   php artisan dply:predeploy                 (range = origin/<branch>..HEAD, else HEAD~1..HEAD)
 *   php artisan dply:predeploy --range=A..B    (explicit commit range)
 *   php artisan dply:predeploy --commit        (also git-commit the changelog files)
 *
 * Both generators are best-effort: each is independently guarded, a failure in
 * one never aborts the other, and the command exits 0 unless an explicit
 * --commit's git commit itself fails.
 */
class PredeployCommand extends Command
{
    protected $signature = 'dply:predeploy
                            {--range= : Commit range to analyze (default: origin/<branch>..HEAD, else HEAD~1..HEAD)}
                            {--commit : git-commit the generated changelog files (CHANGELOG.md + changelog.blade.php)}
                            {--skip-changelog : Skip the AI changelog generator}
                            {--skip-roadmap : Skip the AI roadmap update}';

    protected $description = 'Run pre-deploy AI generators: changelog entry + roadmap refresh.';

    private const BLADE_PATH = 'resources/views/changelog.blade.php';

    private const MD_PATH = 'CHANGELOG.md';

    /** TYPE → changelog.blade.php tag. */
    private const TAG_MAP = [
        'Added' => 'new',
        'Changed' => 'improved',
        'Fixed' => 'fixed',
        'Removed' => 'improved',
        'Security' => 'security',
        'Deprecated' => 'improved',
    ];

    public function handle(): int
    {
        $range = $this->resolveRange();
        if ($range === null) {
            $this->warn('No commit range to analyze — nothing to do.');

            return self::SUCCESS;
        }

        $this->line("Pre-deploy generators for <info>{$range}</info>");

        $changelogChanged = false;
        if (! $this->option('skip-changelog')) {
            $changelogChanged = $this->generateChangelog($range);
        }

        if (! $this->option('skip-roadmap')) {
            $this->updateRoadmap($range);
        }

        if ($changelogChanged && $this->option('commit')) {
            return $this->commitChangelog($range);
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the commit range to analyze. Explicit --range wins; otherwise
     * diff the current branch against its origin counterpart, falling back to
     * the most recent commit when there is no upstream to compare against.
     */
    private function resolveRange(): ?string
    {
        if (($range = (string) $this->option('range')) !== '') {
            return $range;
        }

        $branch = trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD']) ?? '');
        if ($branch !== '' && $branch !== 'HEAD'
            && $this->gitSucceeds(['rev-parse', '--verify', '--quiet', "origin/{$branch}"])) {
            return "origin/{$branch}..HEAD";
        }

        // No upstream — fall back to the single tip commit (if there is a parent).
        if ($this->gitSucceeds(['rev-parse', '--verify', '--quiet', 'HEAD~1'])) {
            return 'HEAD~1..HEAD';
        }

        return $this->gitSucceeds(['rev-parse', '--verify', '--quiet', 'HEAD']) ? 'HEAD' : null;
    }

    /**
     * Generate a changelog entry for $range with the `claude` CLI and prepend it
     * to changelog.blade.php + CHANGELOG.md. Returns true if files were written.
     */
    private function generateChangelog(string $range): bool
    {
        if ($this->which('claude') === null) {
            $this->warn('[changelog] claude CLI not found — skipping.');

            return false;
        }

        $stat = $this->git(['diff', '--stat', $range]) ?? '';
        $patch = $this->git(['diff', $range]) ?? '';
        $diff = trim($stat."\n---\n".$patch);
        if ($diff === '') {
            $this->line('[changelog] empty diff — skipping.');

            return false;
        }
        if (strlen($diff) > 14000) {
            $diff = substr($diff, 0, 14000)."\n... [truncated]";
        }

        $prompt = "Analyze this git diff and respond with EXACTLY three lines, no markdown, no extra text:\n"
            ."TYPE: <Added|Changed|Fixed|Removed|Security|Deprecated>\n"
            ."TITLE: <short 3-6 word public-facing changelog title, title case>\n"
            ."CHANGELOG: <one concise sentence describing the user-visible change>\n\n"
            .$diff;

        $output = $this->runClaude($prompt);
        if ($output === null) {
            $this->warn('[changelog] claude failed/timed out — skipping.');

            return false;
        }

        $type = $this->extractField($output, 'TYPE') ?: 'Changed';
        $title = $this->extractField($output, 'TITLE');
        $entry = ltrim($this->extractField($output, 'CHANGELOG'), '- ');
        if ($entry === '') {
            $this->line('[changelog] no entry produced — skipping.');

            return false;
        }

        $this->line("[changelog] [{$type}] {$title}");
        $this->writeBladeEntry($type, $title, $entry);
        $this->writeMarkdownEntry($type, $entry);

        return true;
    }

    private function writeBladeEntry(string $type, string $title, string $entry): void
    {
        $path = base_path(self::BLADE_PATH);
        if (! is_file($path)) {
            $this->warn('[changelog] '.self::BLADE_PATH.' not found — skipping blade.');

            return;
        }

        $tag = self::TAG_MAP[$type] ?? 'improved';
        $date = Carbon::now()->format('F j, Y');
        $block = "\n"
            ."                [\n"
            ."                    'date'    => '{$date}',\n"
            ."                    'tags'    => ['{$tag}'],\n"
            ."                    'title'   => '".$this->phpEscape($title)."',\n"
            ."                    'summary' => '".$this->phpEscape($entry)."',\n"
            ."                    'items'   => [],\n"
            .'                ],';

        $blade = (string) file_get_contents($path);
        $marker = '$entries = [';
        $pos = strpos($blade, $marker);
        if ($pos === false) {
            $this->warn('[changelog] $entries array not found in '.self::BLADE_PATH);

            return;
        }

        $at = $pos + strlen($marker);
        file_put_contents($path, substr($blade, 0, $at).$block.substr($blade, $at));
        $this->line("  changelog.blade.php: [{$tag}] {$title}");
    }

    private function writeMarkdownEntry(string $type, string $entry): void
    {
        $path = base_path(self::MD_PATH);
        $line = "- {$entry}";

        if (! is_file($path)) {
            file_put_contents($path, "# Changelog\n\n## [Unreleased]\n### {$type}\n{$line}\n");
            $this->line('  CHANGELOG.md: created');

            return;
        }

        $md = (string) file_get_contents($path);
        $marker = '## [Unreleased]';
        if (($pos = strpos($md, $marker)) !== false) {
            $at = $pos + strlen($marker);
            $md = substr($md, 0, $at)."\n### {$type}\n{$line}".substr($md, $at);
        } elseif (preg_match('/\n## /', $md, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1];
            $md = substr($md, 0, $at)."\n\n## [Unreleased]\n### {$type}\n{$line}".substr($md, $at);
        } else {
            $md .= "\n\n## [Unreleased]\n### {$type}\n{$line}\n";
        }

        file_put_contents($path, $md);
        $this->line("  CHANGELOG.md: [{$type}] {$line}");
    }

    /**
     * Refresh the AI roadmap from the tip of $range. Best-effort: the underlying
     * command no-ops when roadmap AI is disabled and never throws on a skipped run.
     */
    private function updateRoadmap(string $range): void
    {
        $tip = str_contains($range, '..') ? substr($range, strpos($range, '..') + 2) : $range;
        $tip = trim($this->git(['rev-parse', '--verify', '--quiet', $tip]) ?? '') ?: 'HEAD';

        $this->line("[roadmap] updating from {$tip} ...");
        try {
            $this->call('dply:roadmap:ai-update', ['--sync' => true, '--commit' => $tip]);
        } catch (\Throwable $e) {
            $this->warn('[roadmap] skipped/failed (non-fatal): '.$e->getMessage());
        }
    }

    private function commitChangelog(string $range): int
    {
        $paths = [self::MD_PATH, self::BLADE_PATH];
        if ($this->gitSucceeds(array_merge(['diff', '--quiet', '--'], $paths))) {
            $this->line('[changelog] no changes to commit.');

            return self::SUCCESS;
        }

        $this->line('[changelog] committing changelog files ...');
        if (! $this->gitSucceeds(array_merge(['commit', '-q', '-m', "docs(changelog): entry for {$range}", '--'], $paths))) {
            $this->error('[changelog] git commit failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // --- helpers ----------------------------------------------------------

    private function extractField(string $output, string $key): string
    {
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (str_starts_with($line, "{$key}:")) {
                return trim(substr($line, strlen($key) + 1));
            }
        }

        return '';
    }

    private function phpEscape(string $s): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], trim($s));
    }

    /** Run `claude -p <prompt>` with a hard wall-clock cap; null on failure/timeout. */
    private function runClaude(string $prompt): ?string
    {
        $timeout = (int) config('dply.changelog_timeout', 90);
        $process = new Process(['claude', '-p', $prompt], base_path(), null, null, (float) $timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        }

        $out = trim($process->getOutput());

        return ($process->isSuccessful() && $out !== '') ? $out : null;
    }

    private function which(string $bin): ?string
    {
        return (new ExecutableFinder)->find($bin);
    }

    /** @param  array<int, string>  $args */
    private function git(array $args): ?string
    {
        $process = new Process(array_merge(['git'], $args), base_path());
        $process->run();

        return $process->isSuccessful() ? rtrim($process->getOutput()) : null;
    }

    /** @param  array<int, string>  $args */
    private function gitSucceeds(array $args): bool
    {
        $process = new Process(array_merge(['git'], $args), base_path());
        $process->run();

        return $process->isSuccessful();
    }
}
