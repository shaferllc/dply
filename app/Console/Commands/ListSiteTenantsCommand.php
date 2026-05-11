<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

class ListSiteTenantsCommand extends Command
{
    protected $signature = 'dply:site:tenant-list
        {site : Site ID, slug, or name}
        {--json : Output as JSON}';

    protected $description = 'List tenant domains for a site.';

    public function handle(): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $tenants = $site->tenantDomains()->orderBy('sort_order')->orderBy('hostname')
            ->get(['id', 'hostname', 'tenant_key', 'label', 'comment'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'hostname' => $t->hostname,
                'tenant_key' => $t->tenant_key,
                'label' => $t->label,
                'comment' => $t->comment,
            ])->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'count' => count($tenants),
                'tenants' => $tenants,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($tenants === []) {
            $this->info("No tenants on {$site->name}.");

            return self::SUCCESS;
        }

        $this->table(['hostname', 'key', 'label', 'comment'], array_map(fn ($t) => [
            $t['hostname'], $t['tenant_key'] ?? '', $t['label'] ?? '', $t['comment'] ?? '',
        ], $tenants));

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
