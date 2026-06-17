<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServerProvider;
use App\Jobs\ApplySiteCdnJob;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Services\Cloudflare\CloudflareCdnService;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use Illuminate\Console\Command;

class CdnEnableCommand extends Command
{
    protected $signature = 'dply:site:cdn-enable
        {site : Site ID, slug, or name}
        {--credential= : ProviderCredential ID (default: first Cloudflare credential in the org)}
        {--zone= : Zone apex (default: derived from primary hostname)}
        {--hostname= : Site hostname to proxy (default: primary domain)}
        {--origin-ip= : Origin IP (default: site server\'s public IP)}
        {--preset=standard : Cache preset (standard|aggressive|bypass)}
        {--sync : Run the apply job inline instead of queuing it}
        {--json : Output result as JSON}';

    protected $description = 'Enable an edge/CDN provider in front of a site.';

    public function handle(): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $credential = $this->resolveCredential($site, (string) ($this->option('credential') ?? ''));
        if ($credential === null) {
            $this->error('No Cloudflare credential available in this site\'s organization.');

            return self::FAILURE;
        }

        $hostname = strtolower(trim((string) ($this->option('hostname') ?: (string) optional($site->primaryDomain())->hostname)));
        $zone = strtolower(trim((string) ($this->option('zone') ?: (Site::apexGuessForHostname($hostname) ?? ''))));
        $originIp = trim((string) ($this->option('origin-ip') ?: (string) $site->server->ip_address));
        $preset = (string) $this->option('preset');

        if ($hostname === '' || $zone === '' || $originIp === '') {
            $this->error('hostname, zone, and origin-ip are all required.');

            return self::FAILURE;
        }
        if (! in_array($preset, [CloudflareCdnService::PRESET_STANDARD, CloudflareCdnService::PRESET_AGGRESSIVE, CloudflareCdnService::PRESET_BYPASS], true)) {
            $this->error('preset must be standard, aggressive, or bypass.');

            return self::FAILURE;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $existing = is_array($meta['cdn'] ?? null) ? $meta['cdn'] : [];
        $meta['cdn'] = array_merge($existing, [
            'enabled' => true,
            'provider' => $credential->provider,
            'credential_id' => $credential->id,
            'zone_name' => $zone,
            'hostname' => $hostname,
            'origin_ip' => $originIp,
            'cache_preset' => $preset,
        ]);
        $site->meta = $meta;
        $site->save();

        app(SiteAuditWriter::class)->record(
            site: $site,
            user: null,
            action: 'site_cdn_enabled',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_CLI,
            summary: "Edge enabled ({$credential->provider}) for {$hostname}",
            payload: ['provider' => $credential->provider, 'zone_name' => $zone, 'hostname' => $hostname, 'cache_preset' => $preset],
        );

        if ($this->option('sync')) {
            (new ApplySiteCdnJob($site->id))->handle();
        } else {
            ApplySiteCdnJob::dispatch($site->id);
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'site_id' => $site->id,
                'provider' => $credential->provider,
                'hostname' => $hostname,
                'zone' => $zone,
                'preset' => $preset,
                'synced' => (bool) $this->option('sync'),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("Edge enabled on {$hostname} via {$credential->provider}.");

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

    private function resolveCredential(Site $site, string $explicitId): ?ProviderCredential
    {
        if ($explicitId !== '') {
            $cred = ProviderCredential::query()->find($explicitId);

            return ($cred && $cred->organization_id === $site->organization_id) ? $cred : null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $site->organization_id)
            ->whereIn('provider', ServerProvider::cdnProviderKeys())
            ->orderBy('updated_at', 'desc')
            ->first();
    }
}
