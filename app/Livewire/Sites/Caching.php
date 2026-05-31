<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-site caching settings. Owns the `meta['caching']` block on Site.
 * Available methods are filtered by site type/runtime + the server's
 * current webserver via {@see Site::availableCachingMethods()}.
 *
 * Saving here writes the meta and dispatches `ApplySiteWebserverConfigJob`
 * so the on-disk vhost picks up the new directives.
 */
#[Layout('layouts.app')]
class Caching extends Component
{
    use DispatchesToastNotifications;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.site_caching';

    /** When true, render the coming-soon teaser instead of the full panel. */
    public bool $comingSoonPreview = false;

    public Server $server;

    public Site $site;

    public bool $enabled = false;

    /** @var list<string> */
    public array $methods = [];

    // Per-method config (nginx_http).
    public string $nginx_fcgi_ttl_200 = '60m';

    public string $nginx_fcgi_ttl_404 = '10m';

    public int $nginx_fcgi_min_uses = 1;

    public string $nginx_proxy_ttl_200 = '60m';

    public string $nginx_proxy_ttl_404 = '10m';

    /** @var list<string> */
    public array $nginx_bypass_cookies = [];

    public string $bypass_cookies_input = '';

    // LSCache (OLS) — single-toggle in v1 with a single TTL knob.
    public bool $lscache_enabled = false;

    public int $lscache_ttl = 120;

    // Varnish per-site default TTL (the daemon is server-level; this drives
    // the X-Dply-Varnish-Default-TTL hint header).
    public bool $varnish_enabled = false;

    public string $varnish_ttl_default = '120s';

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;

        $organization = auth()->user()->currentOrganization();

        if (! Feature::for($organization)->active('workspace.site_caching')) {
            if (workspace_site_caching_preview_active($organization)) {
                $this->comingSoonPreview = true;

                return;
            }

            abort(404);
        }

        $this->hydrateFromSite();
    }

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $organization = auth()->user()->currentOrganization();
        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::for($organization)->active($flag)) {
            abort(404);
        }
    }

    private function hydrateFromSite(): void
    {
        $cfg = $this->site->cachingConfig();
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $methods = $cfg['methods'] ?? [];
        $this->methods = is_array($methods)
            ? array_values(array_filter($methods, 'is_string'))
            : [];

        $nginx = $cfg['nginx_http'] ?? [];
        $fcgi = $nginx['fcgi'] ?? [];
        $proxy = $nginx['proxy'] ?? [];
        $this->nginx_fcgi_ttl_200 = (string) ($fcgi['ttl_200'] ?? '60m');
        $this->nginx_fcgi_ttl_404 = (string) ($fcgi['ttl_404'] ?? '10m');
        $this->nginx_fcgi_min_uses = max(1, (int) ($fcgi['min_uses'] ?? 1));
        $this->nginx_proxy_ttl_200 = (string) ($proxy['ttl_200'] ?? '60m');
        $this->nginx_proxy_ttl_404 = (string) ($proxy['ttl_404'] ?? '10m');
        $cookies = $nginx['bypass_cookies'] ?? [];
        $this->nginx_bypass_cookies = is_array($cookies)
            ? array_values(array_filter($cookies, 'is_string'))
            : [];
        $this->bypass_cookies_input = implode(', ', $this->nginx_bypass_cookies);

        $ls = $cfg['lscache'] ?? [];
        $this->lscache_enabled = (bool) ($ls['enabled'] ?? false);
        $this->lscache_ttl = max(1, (int) ($ls['ttl'] ?? 120));

        $varnish = $cfg['varnish'] ?? [];
        $this->varnish_enabled = (bool) ($varnish['enabled'] ?? false);
        $this->varnish_ttl_default = (string) ($varnish['ttl_default'] ?? '120s');
    }

    /**
     * @return list<string>
     */
    public function getAvailableMethodsProperty(): array
    {
        return $this->site->availableCachingMethods();
    }

    public function toggleMethod(string $method): void
    {
        Gate::authorize('update', $this->site);

        if (! in_array($method, $this->availableMethods, true)) {
            $this->toastError(__('That caching method is not available for this site.'));

            return;
        }

        if (in_array($method, $this->methods, true)) {
            $this->methods = array_values(array_filter($this->methods, fn ($m) => $m !== $method));
        } else {
            $this->methods[] = $method;
        }

        // Keep the dependent toggles in sync — operators who flip nginx_http
        // expect the per-engine "enabled" fields to follow.
        $this->lscache_enabled = in_array('lscache', $this->methods, true);
        $this->varnish_enabled = in_array('varnish', $this->methods, true);
    }

    public function save(): void
    {
        Gate::authorize('update', $this->site);

        // Normalise the comma/newline-separated cookie input into a clean list.
        $cookies = preg_split('/[\s,]+/', trim($this->bypass_cookies_input)) ?: [];
        $cookies = array_values(array_filter(array_map('trim', $cookies), fn ($c) => $c !== ''));
        $this->nginx_bypass_cookies = $cookies;

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['caching'] = [
            'enabled' => $this->enabled,
            'methods' => array_values(array_unique(array_intersect($this->methods, $this->availableMethods))),
            'nginx_http' => [
                'fcgi' => [
                    'ttl_200' => $this->nginx_fcgi_ttl_200,
                    'ttl_404' => $this->nginx_fcgi_ttl_404,
                    'min_uses' => max(1, (int) $this->nginx_fcgi_min_uses),
                ],
                'proxy' => [
                    'ttl_200' => $this->nginx_proxy_ttl_200,
                    'ttl_404' => $this->nginx_proxy_ttl_404,
                ],
                'bypass_cookies' => $cookies,
            ],
            'lscache' => [
                'enabled' => $this->lscache_enabled,
                'ttl' => max(1, (int) $this->lscache_ttl),
                'rules' => [],
            ],
            'varnish' => [
                'enabled' => $this->varnish_enabled,
                'ttl_default' => $this->varnish_ttl_default,
            ],
        ];

        $this->site->meta = $meta;
        $this->site->save();

        // Re-emit the vhost so the new directives land on disk.
        if (class_exists(ApplySiteWebserverConfigJob::class)) {
            ApplySiteWebserverConfigJob::dispatch($this->site->id);
        }

        $this->toastSuccess(__('Caching settings saved and applied.'));
        $this->site = $this->site->fresh() ?? $this->site;
        $this->hydrateFromSite();
    }

    public function render(): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.sites.caching-preview', array_merge(
                SiteSettingsViewData::for(
                    $this->server,
                    $this->site,
                    'caching',
                    null,
                    [],
                    auth()->user(),
                ),
                [
                    'section' => 'caching',
                    'routingTab' => 'domains',
                    'laravel_tab' => 'commands',
                ],
            ));
        }

        return view('livewire.sites.caching', [
            'available' => $this->availableMethods,
        ]);
    }
}
