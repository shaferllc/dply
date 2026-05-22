<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

/**
 * List domain aliases for a site.
 *
 *   dply:site:alias-list <site> [--json]
 */
class ListSiteAliasesCommand extends Command
{
    protected $signature = 'dply:site:alias-list
        {site : Site ID, slug, or name}
        {--json : Output as JSON}';

    protected $description = 'List domain aliases for a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $aliases = $site->domainAliases()->orderBy('sort_order')->orderBy('hostname')
            ->get(['id', 'hostname', 'label', 'comment'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'hostname' => $a->hostname,
                'label' => $a->label,
                'comment' => $a->comment,
            ])->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'count' => count($aliases),
                'aliases' => $aliases,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($aliases === []) {
            $this->info("No aliases on {$site->name}.");

            return self::SUCCESS;
        }

        $this->table(['hostname', 'label', 'comment'], array_map(fn ($a) => [
            $a['hostname'], $a['label'] ?? '', $a['comment'] ?? '',
        ], $aliases));

        return self::SUCCESS;
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
