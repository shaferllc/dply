<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Models\Site;
use App\Services\Deploy\ServerlessDeploymentConfigResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
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
        $meta = is_array($this->meta) ? $this->meta : [];
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
     * The function's globally-unique friendly slug — the one that gives it a
     * clean dply-hosted URL ({app}/fn/{slug}) instead of the raw DigitalOcean
     * Functions invocation URL. Generated and persisted on first access.
     */
    public function ensureServerlessProxySlug(): string
    {
        $existing = (string) ($this->serverlessConfig()['proxy_slug'] ?? '');
        if ($existing !== '') {
            return $existing;
        }

        $base = Str::slug((string) $this->name) ?: 'fn';
        $slug = $base;
        while (static::query()
            ->where('meta->serverless->proxy_slug', $slug)
            ->whereKeyNot($this->getKey())
            ->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        $meta = is_array($this->meta) ? $this->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['proxy_slug'] = $slug;
        $meta['serverless'] = $serverless;
        $this->forceFill(['meta' => $meta])->save();

        return $slug;
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

        $meta = is_array($this->meta) ? $this->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['command_secret'] = $secret;
        $meta['serverless'] = $serverless;
        $this->forceFill(['meta' => $meta])->save();

        return $secret;
    }

    /**
     * The function's live hostname — its proxy slug under a deterministically
     * chosen DPLY_TESTING_DOMAINS entry (e.g. orders-api.dply.cc), matching
     * how VM sites get a testing hostname. Null when no testing domains are
     * configured, in which case the path URL (/fn/{slug}) is the address.
     */
    public function serverlessFunctionHost(): ?string
    {
        $domains = array_values(array_filter(
            (array) config('services.digitalocean.testing_domains', []),
            static fn ($domain): bool => is_string($domain) && trim($domain) !== '',
        ));

        if ($domains === []) {
            return null;
        }

        $domain = trim((string) $domains[abs(crc32((string) $this->getKey())) % count($domains)]);

        return $this->ensureServerlessProxySlug().'.'.$domain;
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
