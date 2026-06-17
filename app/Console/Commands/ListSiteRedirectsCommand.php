<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SiteRedirectKind;
use App\Models\Site;
use Illuminate\Console\Command;

class ListSiteRedirectsCommand extends Command
{
    protected $signature = 'dply:site:redirect-list
        {site : Site ID, slug, or name}
        {--json : Output as JSON}';

    protected $description = 'List redirect rules for a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $rows = $site->redirects()->orderBy('sort_order')->orderBy('from_path')
            ->get(['id', 'kind', 'from_path', 'to_url', 'status_code', 'comment'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'kind' => $r->kind->value,
                'from' => $r->from_path,
                'to' => $r->to_url,
                'code' => (int) $r->status_code,
                'comment' => $r->comment,
            ])->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'count' => count($rows),
                'redirects' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info("No redirects on {$site->name}.");

            return self::SUCCESS;
        }

        $this->table(['kind', 'from', 'to', 'code', 'comment'], array_map(fn ($r) => [
            $r['kind'], $r['from'], $r['to'], (string) $r['code'], $r['comment'] ?? '',
        ], $rows));

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
