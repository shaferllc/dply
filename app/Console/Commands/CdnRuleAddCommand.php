<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplySiteCdnJob;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Services\Cloudflare\CloudflareCdnService;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use Illuminate\Console\Command;

class CdnRuleAddCommand extends Command
{
    protected $signature = 'dply:site:cdn-rule-add
        {site : Site ID, slug, or name}
        {path : Path prefix (e.g. /api/)}
        {--action=bypass : bypass|cache}
        {--ttl=3600 : Edge TTL in seconds (only when --action=cache)}
        {--sync : Push the change to Cloudflare inline}';

    protected $description = 'Append a path cache rule to a site\'s edge config.';

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

        $action = (string) $this->option('action');
        if (! in_array($action, [CloudflareCdnService::RULE_ACTION_BYPASS, CloudflareCdnService::RULE_ACTION_CACHE], true)) {
            $this->error('action must be bypass or cache.');

            return self::FAILURE;
        }

        $path = trim((string) $this->argument('path'));
        if ($path === '') {
            $this->error('path is required.');

            return self::FAILURE;
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $meta = $site->meta;
        $cdn = is_array($meta['cdn'] ?? null) ? $meta['cdn'] : [];
        $rules = is_array($cdn['rules'] ?? null) ? $cdn['rules'] : [];
        $entry = ['path' => $path, 'action' => $action];
        if ($action === CloudflareCdnService::RULE_ACTION_CACHE) {
            $entry['ttl'] = max(1, (int) $this->option('ttl'));
        }
        $rules[] = $entry;
        $cdn['rules'] = ApplySiteCdnJob::normaliseRules($rules);
        $meta['cdn'] = $cdn;
        $site->meta = $meta;
        $site->save();

        app(SiteAuditWriter::class)->record(
            site: $site,
            user: null,
            action: 'site_cdn_rule_added',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_CLI,
            summary: "Edge rule added: {$action} {$path}",
            payload: $entry,
        );

        if (! empty($cdn['enabled'])) {
            if ($this->option('sync')) {
                (new ApplySiteCdnJob($site->id))->handle();
            } else {
                ApplySiteCdnJob::dispatch($site->id);
            }
        }

        $this->info(sprintf('Added %s rule for %s on %s.', $action, $path, $site->name));

        return self::SUCCESS;
    }
}
