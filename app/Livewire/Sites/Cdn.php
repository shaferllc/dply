<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Enums\ServerProvider;
use App\Jobs\ApplySiteCdnJob;
use App\Jobs\PurgeSiteCdnJob;
use App\Jobs\SyncSiteCdnMetricsJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Services\Cloudflare\CloudflareCdnService;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Edge in front" panel — attach a CDN provider (Cloudflare in Phase 1) to a
 * site so traffic for its primary domain flows through the provider's edge
 * before hitting the origin server. Owns the `meta['cdn']` block on Site and
 * dispatches {@see ApplySiteCdnJob} to push the toggle to the provider API.
 */
#[Layout('layouts.app')]
class Cdn extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    public bool $enabled = false;

    public string $provider = 'cloudflare';

    public ?string $credentialId = null;

    public string $zoneName = '';

    public string $hostname = '';

    public string $originIp = '';

    public string $cachePreset = CloudflareCdnService::PRESET_STANDARD;

    /** @var list<array{path: string, action: string, ttl: int}> */
    public array $rules = [];

    public string $newRulePath = '';

    public string $newRuleAction = CloudflareCdnService::RULE_ACTION_BYPASS;

    public int $newRuleTtl = 3600;

    public ?string $lastAppliedAt = null;

    public ?string $lastPurgeAt = null;

    public ?string $lastError = null;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;

        $this->hydrateFromSite();
    }

    private function hydrateFromSite(): void
    {
        $cfg = $this->site->cdnConfig();
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->provider = is_string($cfg['provider'] ?? null) ? $cfg['provider'] : 'cloudflare';
        $this->credentialId = isset($cfg['credential_id']) ? (string) $cfg['credential_id'] : null;
        $this->cachePreset = is_string($cfg['cache_preset'] ?? null)
            ? $cfg['cache_preset']
            : CloudflareCdnService::PRESET_STANDARD;
        $this->lastAppliedAt = is_string($cfg['last_applied_at'] ?? null) ? $cfg['last_applied_at'] : null;
        $this->lastPurgeAt = is_string($cfg['last_purge_at'] ?? null) ? $cfg['last_purge_at'] : null;
        $this->lastError = is_string($cfg['last_error'] ?? null) ? $cfg['last_error'] : null;

        $primary = $this->site->primaryDomain();
        $this->hostname = is_string($cfg['hostname'] ?? null) && $cfg['hostname'] !== ''
            ? (string) $cfg['hostname']
            : (string) ($primary?->hostname ?? '');
        $this->zoneName = is_string($cfg['zone_name'] ?? null) && $cfg['zone_name'] !== ''
            ? (string) $cfg['zone_name']
            : (string) (Site::apexGuessForHostname($this->hostname) ?? '');
        $this->originIp = is_string($cfg['origin_ip'] ?? null) && $cfg['origin_ip'] !== ''
            ? (string) $cfg['origin_ip']
            : (string) ($this->server->ip_address ?? '');

        $this->rules = [];
        foreach (ApplySiteCdnJob::normaliseRules(is_array($cfg['rules'] ?? null) ? $cfg['rules'] : []) as $rule) {
            $this->rules[] = [
                'path' => $rule['path'],
                'action' => $rule['action'],
                'ttl' => (int) ($rule['ttl'] ?? 3600),
            ];
        }
    }

    public function addRule(): void
    {
        Gate::authorize('update', $this->site);

        $path = trim($this->newRulePath);
        if ($path === '') {
            $this->toastError(__('Path is required.'));

            return;
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        if (! in_array($this->newRuleAction, [CloudflareCdnService::RULE_ACTION_BYPASS, CloudflareCdnService::RULE_ACTION_CACHE], true)) {
            $this->toastError(__('Unknown rule action.'));

            return;
        }

        $this->rules[] = [
            'path' => $path,
            'action' => $this->newRuleAction,
            'ttl' => max(1, $this->newRuleTtl),
        ];
        $this->newRulePath = '';
    }

    public function removeRule(int $index): void
    {
        Gate::authorize('update', $this->site);

        if (! isset($this->rules[$index])) {
            return;
        }
        array_splice($this->rules, $index, 1);
        $this->rules = array_values($this->rules);
    }

    /**
     * @return list<ProviderCredential>
     */
    #[Computed]
    public function credentials(): array
    {
        if ($this->site->organization_id === null) {
            return [];
        }

        return ProviderCredential::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereIn('provider', ServerProvider::cdnProviderKeys())
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function save(): void
    {
        Gate::authorize('update', $this->site);

        $this->validate([
            'provider' => 'required|in:cloudflare',
            'credentialId' => 'required|string',
            'zoneName' => 'required|string|min:3',
            'hostname' => 'required|string|min:3',
            'originIp' => 'required|ip',
            'cachePreset' => 'required|in:standard,aggressive,bypass',
        ]);

        $credential = ProviderCredential::query()->find($this->credentialId);
        if ($credential === null
            || $credential->organization_id !== $this->site->organization_id
            || $credential->provider !== $this->provider) {
            $this->toastError(__('Selected credential is not available for this provider.'));

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $existing = is_array($meta['cdn'] ?? null) ? $meta['cdn'] : [];
        $meta['cdn'] = array_merge($existing, [
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'credential_id' => $credential->id,
            'zone_name' => strtolower(trim($this->zoneName)),
            'hostname' => strtolower(trim($this->hostname)),
            'origin_ip' => trim($this->originIp),
            'cache_preset' => $this->cachePreset,
            'rules' => ApplySiteCdnJob::normaliseRules($this->rules),
        ]);

        $this->site->meta = $meta;
        $this->site->save();

        ApplySiteCdnJob::dispatch($this->site->id);

        app(SiteAuditWriter::class)->record(
            site: $this->site,
            user: auth()->user(),
            action: $this->enabled ? 'site_cdn_enabled' : 'site_cdn_disabled',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_WEB,
            summary: $this->enabled
                ? __('Edge enabled (:provider) for :host', ['provider' => $this->provider, 'host' => $this->hostname])
                : __('Edge disabled for :host', ['host' => $this->hostname]),
            payload: [
                'provider' => $this->provider,
                'zone_name' => strtolower(trim($this->zoneName)),
                'hostname' => strtolower(trim($this->hostname)),
                'cache_preset' => $this->cachePreset,
            ],
        );

        $this->toastSuccess($this->enabled
            ? __('Edge enabled. Provider sync queued.')
            : __('Edge disabled. Provider sync queued.'));

        $this->site = $this->site->fresh() ?? $this->site;
        $this->hydrateFromSite();
    }

    public function refreshMetrics(): void
    {
        Gate::authorize('update', $this->site);

        if (! $this->enabled) {
            $this->toastError(__('Enable the edge before fetching metrics.'));

            return;
        }

        SyncSiteCdnMetricsJob::dispatch($this->site->id);
        $this->toastSuccess(__('Metrics refresh queued. Reload in a moment.'));
    }

    public function purge(): void
    {
        Gate::authorize('update', $this->site);

        if (! $this->enabled) {
            $this->toastError(__('Enable the edge before purging.'));

            return;
        }

        PurgeSiteCdnJob::dispatch($this->site->id);

        app(SiteAuditWriter::class)->record(
            site: $this->site,
            user: auth()->user(),
            action: 'site_cdn_purged',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_WEB,
            summary: __('Edge cache purge requested for :host', ['host' => $this->hostname]),
            payload: ['provider' => $this->provider, 'hostname' => $this->hostname],
        );

        $this->toastSuccess(__('Purge queued for :host.', ['host' => $this->hostname]));
    }

    public function render(): View
    {
        return view('livewire.sites.cdn');
    }
}
