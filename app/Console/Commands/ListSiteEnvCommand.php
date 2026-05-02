<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Console\Command;

/**
 * List the environment variables a site has configured.
 *
 *   dply:site:env-list <site> [--environment=production] [--reveal] [--json]
 *
 * Values are MASKED by default. Pass --reveal to print the cleartext
 * — useful for piping into a .env file for local runs, but never the
 * default to avoid leaking secrets into terminal scrollback / shared
 * screens. JSON mode always honors --reveal too.
 *
 * Output is sorted by env_key for deterministic diffing.
 */
class ListSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-list
        {site : Site ID, slug, or name}
        {--environment=production : Environment scope}
        {--reveal : Show full values instead of masked previews}
        {--json : Output as JSON}';

    protected $description = 'List environment variables configured for a site (values masked unless --reveal).';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $environment = (string) ($this->option('environment') ?? 'production');
        $reveal = (bool) $this->option('reveal');

        $rows = SiteEnvironmentVariable::query()
            ->where('site_id', $site->id)
            ->where('environment', $environment)
            ->orderBy('env_key')
            ->get(['env_key', 'env_value']);

        $entries = $rows->map(fn (SiteEnvironmentVariable $v) => [
            'key' => $v->env_key,
            'value' => $reveal ? (string) $v->env_value : $this->mask((string) $v->env_value),
        ])->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'site_name' => $site->name,
                'environment' => $environment,
                'revealed' => $reveal,
                'count' => count($entries),
                'variables' => $entries,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($entries === []) {
            $this->info("No environment variables set for {$site->name} ({$environment}).");

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
