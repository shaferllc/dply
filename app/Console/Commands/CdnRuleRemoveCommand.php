<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplySiteCdnJob;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use Illuminate\Console\Command;

class CdnRuleRemoveCommand extends Command
{
    protected $signature = 'dply:site:cdn-rule-remove
        {site : Site ID, slug, or name}
        {path : Path prefix to remove (exact match)}
        {--sync : Push the change to Cloudflare inline}';

    protected $description = 'Remove a path cache rule from a site\'s edge config (first match by path).';

    public function handle(): int
    {
        $site = Site::query()->where('id', (string) $this->argument('site'))
            ->orWhere('slug', (string) $this->argument('site'))
            ->orWhere('name', (string) $this->argument('site'))
            ->first();
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $path = trim((string) $this->argument('path'));
        if ($path !== '' && ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $cdn = is_array($meta['cdn'] ?? null) ? $meta['cdn'] : [];
        $rules = ApplySiteCdnJob::normaliseRules(is_array($cdn['rules'] ?? null) ? $cdn['rules'] : []);

        $found = false;
        $remaining = [];
        foreach ($rules as $rule) {
            if (! $found && $rule['path'] === $path) {
                $found = true;

                continue;
            }
            $remaining[] = $rule;
        }

        if (! $found) {
            $this->warn("No rule with path {$path} on this site.");

            return self::SUCCESS;
        }

        $cdn['rules'] = $remaining;
        $meta['cdn'] = $cdn;
        $site->meta = $meta;
        $site->save();

        app(SiteAuditWriter::class)->record(
            site: $site,
            user: null,
            action: 'site_cdn_rule_removed',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_CLI,
            summary: "Edge rule removed: {$path}",
            payload: ['path' => $path],
        );

        if (! empty($cdn['enabled'])) {
            if ($this->option('sync')) {
                (new ApplySiteCdnJob($site->id))->handle();
            } else {
                ApplySiteCdnJob::dispatch($site->id);
            }
        }

        $this->info("Removed rule for {$path}.");

        return self::SUCCESS;
    }
}
