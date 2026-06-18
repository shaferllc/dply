<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PurgeSiteCdnJob;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Modules\RemoteCli\Services\RiskLevel;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use Illuminate\Console\Command;

class CdnPurgeCommand extends Command
{
    protected $signature = 'dply:site:cdn-purge
        {site : Site ID, slug, or name}
        {--sync : Run the purge inline instead of queuing it}';

    protected $description = 'Purge the edge cache for a site\'s hostname.';

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
        if (empty($cfg['enabled'])) {
            $this->warn('Edge is not enabled for this site — nothing to purge.');

            return self::SUCCESS;
        }

        app(SiteAuditWriter::class)->record(
            site: $site,
            user: null,
            action: 'site_cdn_purged',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_CLI,
            summary: 'Edge cache purge requested for '.($cfg['hostname'] ?? $site->name),
            payload: ['provider' => $cfg['provider'] ?? null, 'hostname' => $cfg['hostname'] ?? null],
        );

        if ($this->option('sync')) {
            (new PurgeSiteCdnJob($site->id))->handle();
        } else {
            PurgeSiteCdnJob::dispatch($site->id);
        }

        $this->info('Purge dispatched.');

        return self::SUCCESS;
    }
}
