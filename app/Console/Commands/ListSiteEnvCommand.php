<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Console\Command;

/**
 * List the environment variables in a site's encrypted env cache.
 *
 *   dply:site:env-list <site> [--reveal] [--json]
 *
 * Values are MASKED by default. Pass --reveal to print cleartext —
 * useful for piping into a .env file for local runs, but never the
 * default to avoid leaking secrets into terminal scrollback / shared
 * screens. JSON mode honors --reveal too.
 *
 * Output is sorted by env_key for deterministic diffing.
 */
class ListSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-list
        {site : Site ID, slug, or name}
        {--reveal : Show full values instead of masked previews}
        {--json : Output as JSON}';

    protected $description = 'List environment variables in a site\'s env cache (values masked unless --reveal).';

    public function handle(DotEnvFileParser $parser): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $reveal = (bool) $this->option('reveal');
        $vars = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];
        ksort($vars);

        $entries = [];
        foreach ($vars as $key => $value) {
            $entries[] = [
                'key' => $key,
                'value' => $reveal ? (string) $value : $this->mask((string) $value),
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'site_name' => $site->name,
                'revealed' => $reveal,
                'count' => count($entries),
                'variables' => $entries,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($entries === []) {
            $this->info("No environment variables set for {$site->name}.");

            return self::SUCCESS;
        }

        $this->table(
            ['key', $reveal ? 'value' : 'value (masked)'],
            array_map(fn (array $r) => [$r['key'], $r['value']], $entries),
        );

        return self::SUCCESS;
    }

    /**
     * Show first 2 and last 2 chars when long enough to be useful;
     * otherwise just print bullets so we don't reveal short values.
     */
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

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
