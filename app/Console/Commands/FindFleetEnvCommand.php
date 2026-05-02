<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Console\Command;

/**
 * Search for an environment variable key across every site.
 *
 *   dply:fleet:env-find <key>
 *   dply:fleet:env-find DATABASE_URL --reveal
 *   dply:fleet:env-find SECRET --json
 *   dply:fleet:env-find API_ --prefix
 *
 * Prefix mode (--prefix) treats the argument as a key prefix and
 * matches every variable starting with it — useful for finding all
 * AWS_* or STRIPE_* keys at once.
 *
 * Values are masked by default. Output is grouped by site, sorted
 * by site name, then by environment within each site. Empty result
 * exits 1 so scripts can detect "no matches".
 */
class FindFleetEnvCommand extends Command
{
    protected $signature = 'dply:fleet:env-find
        {key : Environment variable key (or prefix with --prefix)}
        {--prefix : Match keys starting with the argument}
        {--reveal : Show full values instead of masked previews}
        {--json : Output as JSON}';

    protected $description = 'Search for an env key across every site in the fleet.';

    public function handle(): int
    {
        $needle = trim((string) $this->argument('key'));
        if ($needle === '') {
            $this->error('Key cannot be empty.');

            return self::FAILURE;
        }

        $reveal = (bool) $this->option('reveal');
        $isPrefix = (bool) $this->option('prefix');

        $query = SiteEnvironmentVariable::query()
            ->select(['id', 'site_id', 'env_key', 'env_value', 'environment']);
        if ($isPrefix) {
            $query->where('env_key', 'like', $this->escapeLike($needle).'%');
        } else {
            $query->where('env_key', $needle);
        }
        $rows = $query->get();

        if ($rows->isEmpty()) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'key' => $needle,
                    'prefix' => $isPrefix,
                    'matches' => [],
                ], JSON_PRETTY_PRINT));
            } else {
                $this->info(sprintf('No matches for "%s"%s.', $needle, $isPrefix ? ' (prefix)' : ''));
            }

            return self::FAILURE;
        }

        $siteIds = $rows->pluck('site_id')->unique()->all();
        $sites = Site::query()->whereIn('id', $siteIds)->get(['id', 'name', 'slug'])->keyBy('id');

        $matches = [];
        foreach ($rows as $r) {
            $site = $sites->get($r->site_id);
            if ($site === null) {
                continue;
            }
            $value = (string) $r->env_value;
            $matches[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_slug' => $site->slug,
                'environment' => $r->environment,
                'key' => $r->env_key,
                'value' => $reveal ? $value : $this->mask($value),
            ];
        }
        usort($matches, function ($a, $b) {
            return [$a['site_name'], $a['environment'], $a['key']]
                <=> [$b['site_name'], $b['environment'], $b['key']];
        });

        if ($this->option('json')) {
            $this->line(json_encode([
                'key' => $needle,
                'prefix' => $isPrefix,
                'revealed' => $reveal,
                'count' => count($matches),
                'matches' => $matches,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d match(es) for "%s"%s:',
            count($matches), $needle, $isPrefix ? ' (prefix)' : ''));
        $this->newLine();

        $rowsForTable = array_map(fn (array $m) => [
            $m['site_name'],
            $m['environment'],
            $m['key'],
            $m['value'],
        ], $matches);
        $this->table(['site', 'env', 'key', $reveal ? 'value' : 'value (masked)'], $rowsForTable);

        return self::SUCCESS;
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len === 0) {
            return '(empty)';
        }
        if ($len <= 6) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 2).str_repeat('•', max(4, $len - 4)).substr($value, -2);
    }
}
