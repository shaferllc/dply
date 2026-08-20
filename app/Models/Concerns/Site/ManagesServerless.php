<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Models\Site;
use App\Modules\Deploy\Services\ServerlessDeploymentConfigResolver;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use App\Support\Preview\UnifiedPreviewHostname;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 *
 * @property array<string, mixed> $meta
 * @property string $serverless_backend
 * @property string $name
 */
trait ManagesServerless
{
    /**
     * True when dply hosts this function on its own FaaS account and therefore
     * bills the customer cost-plus for usage on top of the flat fee.
     */
    public function usesManagedServerless(): bool
    {
        return $this->serverless_backend === self::SERVERLESS_BACKEND_DPLY;
    }

    public function serverlessBackendLabel(): string
    {
        return match ($this->serverless_backend) {
            self::SERVERLESS_BACKEND_DPLY => __('Dply Serverless (managed)'),
            self::SERVERLESS_BACKEND_BYO => __('Your provider account'),
            default => (string) ($this->serverless_backend ?: __('Your provider account')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function functionsConfig(): array
    {
        return $this->serverlessConfig();
    }

    /**
     * @return array<string, mixed>
     */
    public function serverlessConfig(): array
    {
        $meta = $this->meta ?? [];
        $config = $meta['serverless'] ?? $meta['digitalocean_functions'] ?? [];

        return is_array($config) ? $config : [];
    }

    /**
     * Normalised serverless resource limits — memory (MB), timeout (ms), and
     * per-container concurrency — with platform defaults filled in. The
     * DigitalOcean Functions deployer reads these straight onto the
     * OpenWhisk action's `limits` block at deploy time.
     *
     * @return array{memory: int, timeout: int, concurrency: int}
     */
    public function serverlessLimits(): array
    {
        $limits = $this->serverlessConfig()['limits'] ?? [];
        $limits = is_array($limits) ? $limits : [];

        $memory = (int) ($limits['memory'] ?? self::SERVERLESS_DEFAULT_MEMORY_MB);
        if (! in_array($memory, self::SERVERLESS_MEMORY_OPTIONS_MB, true)) {
            $memory = self::SERVERLESS_DEFAULT_MEMORY_MB;
        }

        $timeout = (int) ($limits['timeout'] ?? self::SERVERLESS_DEFAULT_TIMEOUT_MS);
        $timeout = max(self::SERVERLESS_MIN_TIMEOUT_MS, min(self::SERVERLESS_MAX_TIMEOUT_MS, $timeout));

        $concurrency = (int) ($limits['concurrency'] ?? self::SERVERLESS_DEFAULT_CONCURRENCY);
        $concurrency = max(1, min(self::SERVERLESS_MAX_CONCURRENCY, $concurrency));

        return [
            'memory' => $memory,
            'timeout' => $timeout,
            'concurrency' => $concurrency,
        ];
    }

    /**
     * The deployed action's name on the host, or '' when it has never been
     * deployed. Falls back to the trailing segment of the invocation URL for
     * functions deployed before the name was persisted.
     */
    public function serverlessActionName(): string
    {
        $config = $this->serverlessConfig();

        $name = trim((string) ($config['action_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $url = trim((string) ($config['action_url'] ?? ''));

        return $url === '' ? '' : basename(rtrim($url, '/'));
    }

    /**
     * The function's globally-unique friendly slug — the left-hand label of
     * `{slug}-{idHash8}.{serverless apex}` and `/fn/{slug}`. New mints follow
     * the same `{slug}-{8-char sha1}` house style as Edge/VM testing hostnames
     * ({@see UnifiedPreviewHostname::siteLabel()}).
     * Already-allocated slugs are never reminted, so live functions keep
     * answering at the hostname they were given.
     */
    public function ensureServerlessProxySlug(): string
    {
        $existing = (string) ($this->serverlessConfig()['proxy_slug'] ?? '');
        if ($existing !== '') {
            return $existing;
        }

        $slug = $this->mintServerlessProxySlug();
        $candidate = $slug;
        while (static::query()
            ->where('meta->serverless->proxy_slug', $candidate)
            ->whereKeyNot($this->getKey())
            ->exists()) {
            $candidate = rtrim(Str::limit($slug.'-'.Str::lower(Str::random(4)), 63, ''), '-');
        }

        $meta = $this->meta ?? [];
        $serverless = $meta['serverless'] ?? [];
        if (! is_array($serverless)) {
            $serverless = [];
        }
        $serverless['proxy_slug'] = $candidate;
        $meta['serverless'] = $serverless;
        $this->forceFill(['meta' => $meta])->save();

        return $candidate;
    }

    /**
     * Stable new-mint label: `{name-slug}-{8-char sha1 of site id}`.
     * Two sites named "placehold" therefore never share a hostname.
     */
    protected function mintServerlessProxySlug(): string
    {
        $source = trim((string) ($this->slug ?: $this->name));
        $base = trim(Str::slug($source) ?: 'fn', '-');
        $base = $base !== '' ? $base : 'fn';

        $suffixSource = $this->getKey() ?: ($this->server_id ?: $source);
        $suffix = Str::lower(substr(sha1((string) $suffixSource), 0, 8));

        return rtrim(Str::limit($base.'-'.$suffix, 63, ''), '-');
    }

    /**
     * The stable secret dply signs background ticks (scheduler / queue) with.
     *
     * Deliberately separate from {@see webhook_secret}: that one is operator-
     * rotatable, and rotating it must never silently break the function's
     * scheduler. This secret is minted once, persisted in `meta.serverless`,
     * and reused — the deploy bakes it into the function's env and every tick
     * signs with the same value, so the two can never drift apart.
     */
    public function ensureServerlessCommandSecret(): string
    {
        $existing = trim((string) ($this->serverlessConfig()['command_secret'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $secret = Str::random(48);

        $meta = $this->meta ?? [];
        $serverless = $meta['serverless'] ?? [];
        if (! is_array($serverless)) {
            $serverless = [];
        }
        $serverless['command_secret'] = $secret;
        $meta['serverless'] = $serverless;
        $this->forceFill(['meta' => $meta])->save();

        return $secret;
    }

    /**
     * The function's live hostname — its proxy slug under the dedicated
     * serverless apex (e.g. orders-api-a1b2c3d4.dply-serverless.cloud), matching
     * how VM/Edge sites get a `{slug}-{idHash8}` testing hostname. Every
     * function gets one: the apex defaults to dply-serverless.cloud even with
     * no env configuration, so this never falls back to the /fn/{slug} path URL.
     *
     * {@see ServerlessTestingDomains} owns the apex pool — deliberately
     * separate from the shared DPLY_TESTING_DOMAINS preview pool.
     */
    public function serverlessFunctionHost(): ?string
    {
        return $this->ensureServerlessProxySlug()
            .'.'.ServerlessTestingDomains::apexFor($this->getKey());
    }

    /**
     * The public hostname URL operators should share — a ready custom domain,
     * the DNS-provisioned testing host, or `{slug}.{serverless apex}`.
     * Never the raw functions invocation URL. Null until a slug or host exists
     * (does not mint one on read).
     */
    public function serverlessFriendlyUrl(): ?string
    {
        $routing = $this->serverlessConfig()['routing'] ?? [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];
        foreach ($domains as $domain) {
            if (! is_array($domain) || ($domain['dns_status'] ?? null) !== 'ready') {
                continue;
            }

            $host = strtolower(trim((string) ($domain['hostname'] ?? '')));
            if ($host !== '') {
                return 'https://'.$host;
            }
        }

        $dns = $this->serverlessConfig()['dns'] ?? [];
        $dnsHost = is_array($dns) ? strtolower(trim((string) ($dns['hostname'] ?? ''))) : '';
        if ($dnsHost !== '') {
            return 'https://'.$dnsHost;
        }

        $slug = trim((string) ($this->serverlessConfig()['proxy_slug'] ?? ''));
        if ($slug === '') {
            return null;
        }

        return 'https://'.$slug.'.'.ServerlessTestingDomains::apexFor($this->getKey());
    }

    /**
     * The function's canonical public address, in the same priority order the
     * routing screen lists its invocation URLs: live hostname first, then the
     * /fn/{slug} path, then the raw provider invocation URL as a last resort.
     * Null before the first deploy has produced any of them.
     *
     * {@see ResolvesSiteUrls::visitUrl()} can't answer
     * this — a function has no site_domains row and no testing hostname, so it
     * returns null and callers fall back to the site *name*, which reads as a
     * broken URL in the workspace header.
     */
    public function serverlessPublicUrl(): ?string
    {
        $friendly = $this->serverlessFriendlyUrl();
        if ($friendly !== null) {
            return $friendly;
        }

        $host = trim((string) ($this->serverlessFunctionHost() ?? ''));
        if ($host !== '') {
            return 'https://'.$host;
        }

        $slug = trim((string) ($this->serverlessConfig()['proxy_slug'] ?? ''));
        if ($slug !== '') {
            return url('fn/'.$slug);
        }

        $actionUrl = trim((string) ($this->serverlessConfig()['action_url'] ?? ''));

        return $actionUrl !== '' ? $actionUrl : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function serverlessResolvedConfig(): array
    {
        return app(ServerlessDeploymentConfigResolver::class)
            ->resolve($this);
    }
}
