<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ApplySiteCdnJob;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use Illuminate\Console\Command;

class CdnDisableCommand extends Command
{
    protected $signature = 'dply:site:cdn-disable
        {site : Site ID, slug, or name}
        {--sync : Run the apply job inline instead of queuing it}';

    protected $description = 'Disable the edge/CDN in front of a site (flips the proxied record back to grey-cloud).';

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

        $cfg = $site->cdnConfig();
        if (empty($cfg['provider'])) {
            $this->warn('No CDN config on this site — nothing to disable.');

            return self::SUCCESS;
        }

        $meta = $site->meta;
        $meta['cdn'] = array_merge($cfg, ['enabled' => false]);
        $site->meta = $meta;
        $site->save();

        app(SiteAuditWriter::class)->record(
            site: $site,
            user: null,
            action: 'site_cdn_disabled',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_CLI,
            summary: 'Edge disabled for '.($cfg['hostname'] ?? $site->name),
            payload: ['provider' => $cfg['provider'], 'hostname' => $cfg['hostname'] ?? null],
        );

        if ($this->option('sync')) {
            (new ApplySiteCdnJob($site->id))->handle();
        } else {
            ApplySiteCdnJob::dispatch($site->id);
        }

        $this->info('Edge disabled.');

        return self::SUCCESS;
    }
}
