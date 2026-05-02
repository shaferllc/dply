<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Console\Command;

/**
 * Compare environment variables across two scopes for a site.
 *
 *   dply:site:env-diff <site> [--from=production] [--to=staging]
 *                             [--reveal] [--json]
 *
 * Reports three buckets:
 *   - only_in_from: keys configured in --from but missing in --to
 *   - only_in_to:   keys configured in --to but missing in --from
 *   - differs:      keys present in both but with different values
 *
 * Values are MASKED by default (same masking rule as env-list).
 * --reveal prints cleartext for both sides — useful for "why is
 * staging configured differently?" investigation but obviously
 * leaks secrets to scrollback.
 *
 * Designed for "production vs staging drift" workflows. Exits with
 * code 0 always — drift is a state to surface, not an error.
 */
class DiffSiteEnvCommand extends Command
{
    protected $signature = 'dply:site:env-diff
        {site : Site ID, slug, or name}
        {--from=production : Environment on the left side of the diff}
        {--to=staging : Environment on the right side of the diff}
        {--reveal : Show full values instead of masked previews}
        {--json : Output as JSON}';

    protected $description = 'Compare environment variables across two scopes for a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $from = (string) ($this->option('from') ?? 'production');
        $to = (string) ($this->option('to') ?? 'staging');
        if ($from === $to) {
            $this->error('--from and --to must differ.');

            return self::FAILURE;
        }
        $reveal = (bool) $this->option('reveal');

        $fromVars = $this->loadVars($site, $from);
        $toVars = $this->loadVars($site, $to);

        $onlyInFrom = array_keys(array_diff_key($fromVars, $toVars));
        $onlyInTo = array_keys(array_diff_key($toVars, $fromVars));
        $shared = array_intersect_key($fromVars, $toVars);
        $differs = [];
        foreach ($shared as $key => $fromVal) {
            $toVal = $toVars[$key];
            if ($fromVal !== $toVal) {
                $differs[$key] = [
                    'from' => $reveal ? $fromVal : $this->mask($fromVal),
                    'to' => $reveal ? $toVal : $this->mask($toVal),
                ];
            }
        }
        sort($onlyInFrom);
        sort($onlyInTo);
        ksort($differs);

        $payload = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'from' => $from,
            'to' => $to,
            'revealed' => $reveal,
            'only_in_from' => $onlyInFrom,
            'only_in_to' => $onlyInTo,
            'differs' => $differs,
            'in_sync' => $onlyInFrom === [] && $onlyInTo === [] && $differs === [],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($site, $payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function loadVars(Site $site, string $environment): array
    {
        $rows = SiteEnvironmentVariable::query()
            ->where('site_id', $site->id)
            ->where('environment', $environment)
            ->get(['env_key', 'env_value']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->env_key] = (string) $r->env_value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function renderHuman(Site $site, array $p): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<fg=cyan>env-diff</> for <fg=white;options=bold>%s</> — <fg=yellow>%s</> ↔ <fg=yellow>%s</>',
            $site->name,
            $p['from'],
            $p['to'],
        ));

        if ($p['in_sync']) {
            $this->newLine();
            $this->info('Environments are in sync.');

            return;
        }

        if ($p['only_in_from'] !== []) {
            $this->newLine();
            $this->line(sprintf('<fg=red>Only in %s:</>', $p['from']));
            foreach ($p['only_in_from'] as $k) {
                $this->line('  -  '.$k);
            }
        }

        if ($p['only_in_to'] !== []) {
            $this->newLine();
            $this->line(sprintf('<fg=green>Only in %s:</>', $p['to']));
            foreach ($p['only_in_to'] as $k) {
                $this->line('  +  '.$k);
            }
        }

        if ($p['differs'] !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Differs:</>');
            foreach ($p['differs'] as $k => $pair) {
                $this->line(sprintf('  %s', $k));
                $this->line(sprintf('    %s → %s', $pair['from'], $pair['to']));
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
