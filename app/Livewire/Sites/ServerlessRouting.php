<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Services\Serverless\ServerlessCustomDomainProvisioner;
use App\Services\Serverless\ServerlessRoutingResolver;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * NETWORKING > Routing — manages the dply edge proxy for a serverless app.
 *
 * Five tabs:
 *   - hostname:    auto-provisioned `{slug}.{testing-domain}` + DNS state
 *                  (lifts the existing DnsPanel component).
 *   - domains:     operator-owned custom hostnames (api.acme.com) pointed
 *                  at this function via DNS the operator (or dply) writes.
 *   - redirects:   path-based redirects served by the proxy controller
 *                  before forwarding upstream.
 *   - headers:     static response headers + CORS policy merged onto the
 *                  proxied response.
 *   - invocation:  read-only list of the three URL families this function
 *                  is reachable at (raw DO Functions / dply edge / custom).
 *
 * Distinct from the VM `routing` (which edits nginx server blocks) — this
 * is the edge proxy's surface, hence the sidebar item lives under the
 * `networking` group with a dedicated `sites.routing` route rather than
 * piggy-backing on the wildcard `section` router.
 */
#[Layout('layouts.app')]
class ServerlessRouting extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    #[Url(as: 'tab', except: 'hostname')]
    public string $tab = 'hostname';

    public string $newDomainHostname = '';

    public string $newRedirectFrom = '';

    public string $newRedirectTo = '';

    public int $newRedirectStatus = 302;

    /** @var list<array{name: string, value: string}> */
    public array $headers = [];

    public string $newHeaderName = '';

    public string $newHeaderValue = '';

    public bool $corsEnabled = false;

    public string $corsOrigins = '';

    public string $corsMethods = 'GET, POST, OPTIONS';

    public string $corsHeaders = 'Content-Type, Authorization';

    public bool $corsAllowCredentials = false;

    public int $corsMaxAge = 3600;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);

        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;

        $this->loadHeadersAndCorsFromMeta();
    }

    /* ──────────── Custom domains ──────────── */

    public function addCustomDomain(ServerlessCustomDomainProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $hostname = strtolower(trim($this->newDomainHostname));
        if ($hostname === '' || ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $hostname)) {
            $this->toastError(__('Enter a valid hostname (e.g. api.acme.com).'));

            return;
        }

        $entry = $provisioner->provision($this->site->fresh(), $hostname);
        if ($entry === null) {
            $this->toastError(__('Could not attach :host — try again.', ['host' => $hostname]));

            return;
        }

        $this->newDomainHostname = '';
        $this->site->refresh();
        $this->renderMessageForDomainStatus($entry);
    }

    public function verifyCustomDomain(string $hostname, ServerlessCustomDomainProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $entry = $provisioner->verify($this->site->fresh(), $hostname);
        if ($entry === null) {
            $this->toastError(__('Could not verify :host.', ['host' => $hostname]));

            return;
        }

        $this->site->refresh();
        $this->renderMessageForDomainStatus($entry);
    }

    public function reprovisionCustomDomain(string $hostname, ServerlessCustomDomainProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $entry = $provisioner->provision($this->site->fresh(), $hostname);
        if ($entry === null) {
            $this->toastError(__('Could not re-provision :host.', ['host' => $hostname]));

            return;
        }

        $this->site->refresh();
        $this->renderMessageForDomainStatus($entry);
    }

    public function removeCustomDomain(string $hostname, ServerlessCustomDomainProvisioner $provisioner): void
    {
        Gate::authorize('update', $this->site);

        $provisioner->remove($this->site->fresh(), $hostname);
        $this->site->refresh();
        $this->toastSuccess(__('Removed :host.', ['host' => $hostname]));
    }

    /* ──────────── Redirects ──────────── */

    public function addRedirect(): void
    {
        Gate::authorize('update', $this->site);

        $from = trim($this->newRedirectFrom);
        $to = trim($this->newRedirectTo);
        $status = $this->newRedirectStatus;
        if ($from === '' || $to === '') {
            $this->toastError(__('From path and target URL are both required.'));

            return;
        }
        if (! in_array($status, [301, 302, 307, 308], true)) {
            $status = 302;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];
        $redirects = is_array($routing['redirects'] ?? null) ? $routing['redirects'] : [];

        $redirects[] = [
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'kind' => 'exact',
        ];

        $routing['redirects'] = array_values($redirects);
        $serverless['routing'] = $routing;
        $meta['serverless'] = $serverless;
        $this->site->forceFill(['meta' => $meta])->save();

        app(ServerlessRoutingResolver::class)->invalidate($this->site);

        $this->newRedirectFrom = '';
        $this->newRedirectTo = '';
        $this->newRedirectStatus = 302;
        $this->toastSuccess(__('Redirect added.'));
    }

    public function removeRedirect(int $index): void
    {
        Gate::authorize('update', $this->site);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];
        $redirects = is_array($routing['redirects'] ?? null) ? $routing['redirects'] : [];

        if (! isset($redirects[$index])) {
            return;
        }

        array_splice($redirects, $index, 1);
        $routing['redirects'] = array_values($redirects);
        $serverless['routing'] = $routing;
        $meta['serverless'] = $serverless;
        $this->site->forceFill(['meta' => $meta])->save();

        app(ServerlessRoutingResolver::class)->invalidate($this->site);
        $this->toastSuccess(__('Redirect removed.'));
    }

    /* ──────────── Headers & CORS ──────────── */

    public function addHeader(): void
    {
        Gate::authorize('update', $this->site);

        $name = trim($this->newHeaderName);
        $value = $this->newHeaderValue;
        if ($name === '') {
            $this->toastError(__('Header name is required.'));

            return;
        }
        if (in_array(strtolower($name), ['content-type', 'cache-control', 'location'], true)) {
            $this->toastError(__('Cannot override :name from this list — set it in your function instead.', ['name' => $name]));

            return;
        }

        $this->headers[] = ['name' => $name, 'value' => $value];
        $this->newHeaderName = '';
        $this->newHeaderValue = '';
        $this->persistHeadersAndCors();
        $this->toastSuccess(__('Header added.'));
    }

    public function removeHeader(int $index): void
    {
        Gate::authorize('update', $this->site);

        if (isset($this->headers[$index])) {
            array_splice($this->headers, $index, 1);
            $this->persistHeadersAndCors();
            $this->toastSuccess(__('Header removed.'));
        }
    }

    public function saveCors(): void
    {
        Gate::authorize('update', $this->site);

        $this->persistHeadersAndCors();
        $this->toastSuccess(__('CORS settings saved.'));
    }

    /* ──────────── Render ──────────── */

    public function render(): View
    {
        $runtimeMode = $this->site->runtimeTargetMode();
        $this->site->refresh();
        $resolver = app(ServerlessRoutingResolver::class);
        $routing = $resolver->forSite($this->site);

        $serverless = is_array($this->site->meta['serverless'] ?? null) ? $this->site->meta['serverless'] : [];
        $invocationUrls = $this->invocationUrls($routing['custom_domains']);

        return view('livewire.sites.serverless-routing', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'routing',
            'customDomains' => $routing['custom_domains'],
            'redirects' => $routing['redirects'],
            'cors' => $routing['cors'],
            'rawInvocationUrl' => (string) ($serverless['action_url'] ?? ''),
            'edgeHost' => (string) ($this->site->serverlessFunctionHost() ?? ''),
            'proxySlug' => (string) ($serverless['proxy_slug'] ?? ''),
            'invocationUrls' => $invocationUrls,
            'corsAllowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
        ]);
    }

    /* ──────────── Private ──────────── */

    private function loadHeadersAndCorsFromMeta(): void
    {
        $serverless = is_array($this->site->meta['serverless'] ?? null) ? $this->site->meta['serverless'] : [];
        $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];

        $rawHeaders = is_array($routing['headers'] ?? null) ? $routing['headers'] : [];
        $this->headers = [];
        foreach ($rawHeaders as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $this->headers[] = ['name' => $name, 'value' => (string) ($entry['value'] ?? '')];
        }

        $cors = is_array($routing['cors'] ?? null) ? $routing['cors'] : [];
        $this->corsEnabled = (bool) ($cors['enabled'] ?? false);
        $this->corsOrigins = implode(', ', is_array($cors['origins'] ?? null) ? $cors['origins'] : []);
        $this->corsMethods = implode(', ', is_array($cors['methods'] ?? null) ? $cors['methods'] : ['GET', 'POST', 'OPTIONS']);
        $this->corsHeaders = implode(', ', is_array($cors['headers'] ?? null) ? $cors['headers'] : ['Content-Type', 'Authorization']);
        $this->corsAllowCredentials = (bool) ($cors['allow_credentials'] ?? false);
        $this->corsMaxAge = max(0, (int) ($cors['max_age'] ?? 3600));
    }

    private function persistHeadersAndCors(): void
    {
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];

        $routing['headers'] = array_values($this->headers);
        $routing['cors'] = [
            'enabled' => $this->corsEnabled,
            'origins' => $this->splitCsv($this->corsOrigins),
            'methods' => $this->splitCsv($this->corsMethods),
            'headers' => $this->splitCsv($this->corsHeaders),
            'allow_credentials' => $this->corsAllowCredentials,
            'max_age' => max(0, $this->corsMaxAge),
        ];

        $serverless['routing'] = $routing;
        $meta['serverless'] = $serverless;
        $this->site->forceFill(['meta' => $meta])->save();

        app(ServerlessRoutingResolver::class)->invalidate($this->site);
    }

    /**
     * @return list<string>
     */
    private function splitCsv(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn (string $v) => $v !== ''));
    }

    /**
     * @param  list<array<string, mixed>>  $customDomains
     * @return list<array{label: string, url: string, scope: string}>
     */
    private function invocationUrls(array $customDomains): array
    {
        $serverless = is_array($this->site->meta['serverless'] ?? null) ? $this->site->meta['serverless'] : [];
        $out = [];

        $raw = (string) ($serverless['action_url'] ?? '');
        if ($raw !== '') {
            $out[] = ['label' => __('Raw DigitalOcean Functions URL'), 'url' => $raw, 'scope' => 'upstream'];
        }

        $edge = (string) ($this->site->serverlessFunctionHost() ?? '');
        if ($edge !== '') {
            $out[] = ['label' => __('Edge subdomain'), 'url' => 'https://'.$edge, 'scope' => 'edge'];
        }

        $proxy = (string) ($serverless['proxy_slug'] ?? '');
        if ($proxy !== '') {
            $out[] = ['label' => __('Edge path'), 'url' => url('fn/'.$proxy), 'scope' => 'edge'];
        }

        foreach ($customDomains as $domain) {
            if (($domain['dns_status'] ?? null) !== 'ready') {
                continue;
            }
            $out[] = ['label' => __('Custom domain'), 'url' => 'https://'.$domain['hostname'], 'scope' => 'custom'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function renderMessageForDomainStatus(array $entry): void
    {
        $status = (string) ($entry['dns_status'] ?? 'pending');
        $hostname = (string) ($entry['hostname'] ?? '');
        match ($status) {
            'ready' => $this->toastSuccess(__(':host is live.', ['host' => $hostname])),
            'failed' => $this->toastError(__(':host could not be set up: :err', ['host' => $hostname, 'err' => (string) ($entry['error'] ?? 'unknown error')])),
            'pending' => $this->toastSuccess(__(':host attached. Create the CNAME at your DNS provider, then click Verify.', ['host' => $hostname])),
            default => $this->toastSuccess($hostname),
        };
    }
}
