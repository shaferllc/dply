<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Compile every roadmap markdown in docs/ into a single ROADMAPS.md so
 * collaborators have one place to skim "what's planned / shipped".
 *
 *   php artisan dply:docs:compile-roadmaps
 *   php artisan dply:docs:compile-roadmaps --check    (CI: fail if drift)
 *   php artisan dply:docs:compile-roadmaps --output=docs/CUSTOM.md
 *
 * Discovery rule: any `docs/*.md` whose filename contains "roadmap"
 * (case-insensitive), excluding the output file. Order is alphabetical
 * so the output is deterministic regardless of who runs the command.
 */
class CompileRoadmapsCommand extends Command
{
    protected $signature = 'dply:docs:compile-roadmaps
                            {--output=docs/ROADMAPS.md : Path (relative to base_path) for the compiled file}
                            {--check : Exit non-zero if the compiled output would differ from the file on disk (no write)}';

    protected $description = 'Concatenate every docs/*roadmap*.md into one ROADMAPS.md with a TOC and source banners.';

    public function handle(): int
    {
        $docsDir = base_path('docs');
        if (! is_dir($docsDir)) {
            $this->error("docs/ directory not found at {$docsDir}");

            return self::FAILURE;
        }

        $outputRelative = (string) $this->option('output');
        $outputAbsolute = base_path($outputRelative);
        $outputBasename = basename($outputAbsolute);

        $sources = collect(glob($docsDir.'/*.md') ?: [])
            ->reject(fn (string $path): bool => basename($path) === $outputBasename)
            ->filter(fn (string $path): bool => str_contains(strtolower(basename($path)), 'roadmap'))
            // Alpha-sort with a twist: a parent file ("edge-roadmap.md")
            // comes before its continuations ("edge-roadmap-next.md") even
            // though `.` > `-` in ASCII would otherwise reverse them.
            ->sort(function (string $a, string $b): int {
                $aBase = basename($a, '.md');
                $bBase = basename($b, '.md');
                if (str_starts_with($bBase, $aBase.'-')) {
                    return -1;
                }
                if (str_starts_with($aBase, $bBase.'-')) {
                    return 1;
                }

                return strcasecmp($aBase, $bBase);
            })
            ->values();

        if ($sources->isEmpty()) {
            $this->warn('No docs/*roadmap*.md files found — nothing to compile.');

            return self::SUCCESS;
        }

        $compiled = $this->compile($sources->all(), $outputRelative);

        if ($this->option('check')) {
            $existing = is_file($outputAbsolute) ? (string) file_get_contents($outputAbsolute) : '';
            // Strip the "Last compiled" date line from both sides before
            // comparing — otherwise re-running on a different day would
            // always report drift even when source content is identical.
            $normalize = fn (string $s): string => preg_replace('/Last compiled \*\*\d{4}-\d{2}-\d{2}\*\*/u', 'Last compiled **<DATE>**', $s) ?? $s;
            if ($normalize($existing) !== $normalize($compiled)) {
                $this->error($outputRelative.' is out of date. Run: php artisan dply:docs:compile-roadmaps');

                return self::FAILURE;
            }
            $this->info($outputRelative.' is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($outputAbsolute, $compiled);

        $this->info(sprintf(
            'Compiled %d roadmap%s into %s (%d bytes).',
            $sources->count(),
            $sources->count() === 1 ? '' : 's',
            $outputRelative,
            strlen($compiled),
        ));
        foreach ($sources as $source) {
            $this->line('  · '.ltrim(str_replace(base_path(), '', $source), '/'));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $sources  Absolute paths to source markdown files.
     */
    private function compile(array $sources, string $outputRelative): string
    {
        $today = Carbon::now()->toDateString();
        $sections = [];
        $toc = [];

        foreach ($sources as $i => $sourcePath) {
            $relativeSource = 'docs/'.basename($sourcePath);
            $sourceLink = basename($sourcePath);
            $body = (string) file_get_contents($sourcePath);

            $title = $this->extractTitle($body) ?? Str::headline(Str::beforeLast(basename($sourcePath), '.md'));
            $status = $this->extractStatus($body);
            $anchor = $this->anchor(($i + 1).'. '.$title);

            $tocLine = sprintf('%d. [%s](#%s) — `%s`%s', $i + 1, $title, $anchor, $relativeSource, $status !== null ? ' · _'.$status.'_' : '');
            $toc[] = $tocLine;

            $sections[] = sprintf(
                "## %d. %s\n\n> Source: [`%s`](%s)\n\n%s",
                $i + 1,
                $title,
                $relativeSource,
                $sourceLink,
                rtrim($body),
            );
        }

        $tocBlock = implode("\n", $toc);
        $sectionsBlock = implode("\n\n---\n\n", $sections);

        return <<<MARKDOWN
        # dply roadmaps (compiled)

        > Auto-generated by `php artisan dply:docs:compile-roadmaps`. Last compiled **{$today}**.
        > Source files in `docs/` are canonical — edit those, then rerun the command.
        > Drift check: `php artisan dply:docs:compile-roadmaps --check` (CI-friendly, exits non-zero on drift).

        ## Contents

        {$tocBlock}

        ---

        {$sectionsBlock}

        MARKDOWN;
    }

    private function extractTitle(string $body): ?string
    {
        if (preg_match('/^#\s+(.+?)\s*$/m', $body, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractStatus(string $body): ?string
    {
        // Match a "Status: …" line in the first ~20 lines of the file so
        // we don't pick up stale status mentions deep in the content.
        // Stop at the first sentence boundary (period followed by space-then-text)
        // OR end of line — whichever comes first. Avoids dragging in second-
        // sentence explanations after the one-phrase status (e.g. the serverless
        // roadmap's "in flight (audit 2026-05-24). Decided 2026-05-21 via …").
        $head = implode("\n", array_slice(preg_split('/\r?\n/', $body) ?: [], 0, 20));
        if (preg_match('/^Status:\s*(.+?)(?:\.\s+\S|\s*$)/mi', $head, $m) === 1) {
            return rtrim(trim($m[1]), '.');
        }

        return null;
    }

    /**
     * GitHub-flavored markdown heading anchor: lowercase, alphanumerics +
     * hyphens, collapse runs, strip ends. Section numbering ("1. ") is part
     * of the heading text so the anchor includes it as "1-title-slug".
     */
    private function anchor(string $heading): string
    {
        $slug = strtolower($heading);
        $slug = preg_replace('/[^a-z0-9\s-]/u', '', $slug) ?? '';
        $slug = preg_replace('/\s+/', '-', trim($slug)) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
