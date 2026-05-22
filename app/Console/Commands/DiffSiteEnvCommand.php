<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Console\Command;

/**
 * Compare a site's encrypted env cache to the live `.env` on the server.
 *
 *   dply:site:env-diff <site> [--reveal] [--json]
 *
 * Reports three buckets:
 *   - only_in_cache:  keys we have locally but the server file doesn't
 *   - only_in_server: keys present on disk but missing from our cache (drift)
 *   - differs:        keys present in both with different values
 *
 * Values are MASKED by default. --reveal prints cleartext.
 *
 * For runtimes without a server file (Docker / K8s / Serverless), the
 * cache IS the truth — the command exits 0 with `unsupported: true` in
 * the JSON payload so scripts can short-circuit.
 *
 * Designed for "did someone edit the .env on disk out-of-band?" sweeps.
 * Exits with code 0 always — drift is a state to surface, not an error.
 */
class DiffSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-diff
        {site : Site ID, slug, or name}
        {--reveal : Show full values instead of masked previews}
        {--json : Output as JSON}';

    protected $description = 'Compare a site\'s env cache to the live .env on the server.';

    public function handle(DotEnvFileParser $parser, SiteEnvReader $reader): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $reveal = (bool) $this->option('reveal');
        $unsupported = ! $site->server?->hostCapabilities()->supportsEnvPushToHost();

        if ($unsupported) {
            $payload = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'unsupported' => true,
                'message' => 'This site\'s runtime does not have a server .env file.',
            ];
            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT));
            } else {
                $this->info($payload['message']);
            }

            return self::SUCCESS;
        }

        $cacheVars = $parser->parse((string) ($site->env_file_content ?? ''))['variables'];

        try {
            $serverRaw = $reader->read($site);
        } catch (\Throwable $e) {
            $payload = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'error' => $e->getMessage(),
            ];
            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT));
            } else {
                $this->error('Could not read .env from server: '.$e->getMessage());
            }

            return self::SUCCESS;
        }

        $serverVars = $parser->parse($serverRaw)['variables'];

        $onlyInCache = array_keys(array_diff_key($cacheVars, $serverVars));
        $onlyInServer = array_keys(array_diff_key($serverVars, $cacheVars));
        $shared = array_intersect_key($cacheVars, $serverVars);
        $differs = [];
        foreach ($shared as $key => $cacheVal) {
            $serverVal = $serverVars[$key];
            if ($cacheVal !== $serverVal) {
                $differs[$key] = [
                    'cache' => $reveal ? $cacheVal : $this->mask($cacheVal),
                    'server' => $reveal ? $serverVal : $this->mask($serverVal),
                ];
            }
        }
        sort($onlyInCache);
        sort($onlyInServer);
        ksort($differs);

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'revealed' => $reveal,
            'only_in_cache' => $onlyInCache,
            'only_in_server' => $onlyInServer,
            'differs' => $differs,
            'in_sync' => $onlyInCache === [] && $onlyInServer === [] && $differs === [],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($site, $payload);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function renderHuman(Site $site, array $p): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<fg=cyan>env-diff</> for <fg=white;options=bold>%s</> — <fg=yellow>cache</> ↔ <fg=yellow>server</>',
            $site->name,
        ));

        if ($p['in_sync']) {
            $this->newLine();
            $this->info('Cache and server are in sync.');

            return;
        }

        if ($p['only_in_cache'] !== []) {
            $this->newLine();
            $this->line('<fg=red>Only in cache (not yet pushed):</>');
            foreach ($p['only_in_cache'] as $k) {
                $this->line('  -  '.$k);
            }
        }

        if ($p['only_in_server'] !== []) {
            $this->newLine();
            $this->line('<fg=green>Only on server (drift):</>');
            foreach ($p['only_in_server'] as $k) {
                $this->line('  +  '.$k);
            }
        }

        if ($p['differs'] !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Differs:</>');
            foreach ($p['differs'] as $k => $pair) {
                $this->line(sprintf('  %s', $k));
                $this->line(sprintf('    cache: %s', $pair['cache']));
                $this->line(sprintf('    server: %s', $pair['server']));
            }
        }
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
