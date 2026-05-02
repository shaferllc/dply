<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Search the dply CLI catalog by keyword.
 *
 *   dply:cli-search env            # commands with "env" anywhere
 *   dply:cli-search "fleet|stale"  # alternation works (regex)
 *   dply:cli-search domain --names-only
 *   dply:cli-search audit --json
 *
 * Matches against both command names and descriptions, case-insensitive.
 * Useful when you're hunting for "does dply have a command for X?" and
 * don't want to scroll the full php artisan list.
 *
 * The keyword is treated as a regex (so dots match). Pass a literal
 * string with no metacharacters and it just becomes a substring search.
 */
class CliSearchCommand extends Command
{
    protected $signature = 'dply:cli-search
        {keyword : Substring or regex to match against name + description}
        {--names-only : Match against command names only (skip descriptions)}
        {--json : Output as JSON}';

    protected $description = 'Search the dply CLI catalog by keyword.';

    public function handle(): int
    {
        $needle = (string) $this->argument('keyword');
        if ($needle === '') {
            $this->error('Keyword cannot be empty.');

            return self::FAILURE;
        }

        $namesOnly = (bool) $this->option('names-only');
        // Build a case-insensitive regex. preg_quote isn't applied because
        // we DO want regex syntax (alternation, etc) — operators wanting a
        // literal substring just won't include metacharacters.
        $pattern = '/'.str_replace('/', '\\/', $needle).'/i';

        $matches = [];
        foreach (Artisan::all() as $name => $command) {
            if (! str_starts_with($name, 'dply:')) {
                continue;
            }
            $description = (string) $command->getDescription();
            $haystack = $namesOnly ? $name : ($name.' '.$description);

            if (@preg_match($pattern, $haystack) === 1) {
                $matches[] = [
                    'name' => $name,
                    'description' => $description,
                ];
            }
        }
        usort($matches, fn ($a, $b) => $a['name'] <=> $b['name']);

        if ($this->option('json')) {
            $this->line(json_encode([
                'keyword' => $needle,
                'names_only' => $namesOnly,
                'count' => count($matches),
                'matches' => $matches,
            ], JSON_PRETTY_PRINT));

            return $matches === [] ? self::FAILURE : self::SUCCESS;
        }

        if ($matches === []) {
            $this->info(sprintf('No dply commands match "%s".', $needle));

            return self::FAILURE;
        }

        $this->info(sprintf('%d match(es) for "%s":', count($matches), $needle));
        $this->newLine();
        foreach ($matches as $m) {
            $this->line(sprintf('  <fg=cyan>%s</>', $m['name']));
            $this->line(sprintf('    <fg=gray>%s</>', $m['description']));
        }

        return self::SUCCESS;
    }
}
