<?php

namespace App\Services\Servers;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\HetznerService;
use App\Services\VultrService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Looks up the catalog price for a server's current size on its provider, and
 * derives a runtime-based MTD/YTD estimate from that hourly rate.
 *
 * We deliberately surface "catalog list price × runtime" — not the actual
 * invoiced amount. Per-resource invoiced billing is gated by extra scopes,
 * differs wildly per provider, and rarely attributes cleanly to a single
 * server (snapshots, volumes, transfer roll up at the account level).
 */
class ServerProviderCostEstimator
{
    public const CACHE_TTL_SECONDS = 3600;

    public const HOURS_PER_MONTH = 730.0;

    /**
     * Providers we know how to look up prices for.
     */
    public static function isSupported(?ServerProvider $provider): bool
    {
        if (! $provider instanceof ServerProvider) {
            return false;
        }

        return in_array($provider, [
            ServerProvider::DigitalOcean,
            ServerProvider::Hetzner,
            ServerProvider::Vultr,
            ServerProvider::EquinixMetal,
        ], true);
    }

    /**
     * @return array{
     *   monthly: float,
     *   hourly: float,
     *   currency: string,
     *   plan: string,
     *   provider_label: string,
     *   source: string,
     *   fetched_at: CarbonImmutable,
     *   formatted: string,
     *   mtd: float,
     *   ytd: float,
     *   runtime_hours_month: float,
     *   runtime_hours_year: float,
     * }
     *
     * @throws ProviderCostUnavailableException when the lookup cannot complete.
     */
    public function estimate(Server $server): array
    {
        $provider = $server->provider;
        if (! $provider instanceof ServerProvider) {
            throw new ProviderCostUnavailableException(
                __('This server has no recorded provider, so cost cannot be looked up.')
            );
        }

        if (! $server->providerCredential) {
            throw new ProviderCostUnavailableException(
                __('No saved provider credential is linked to this server. Reconnect a credential to pull pricing.')
            );
        }

        if ((string) $server->size === '') {
            throw new ProviderCostUnavailableException(
                __('No size/plan is recorded for this server, so the catalog price cannot be matched.')
            );
        }

        $base = match ($provider) {
            ServerProvider::DigitalOcean => $this->lookupDigitalOcean($server),
            ServerProvider::Hetzner => $this->lookupHetzner($server),
            ServerProvider::Vultr => $this->lookupVultr($server),
            ServerProvider::EquinixMetal => $this->lookupEquinixMetal($server),
            default => throw new ProviderCostUnavailableException(
                __('Pulling cost from :provider is not supported yet — type the value into the field instead.', [
                    'provider' => $provider->label(),
                ])
            ),
        };

        return $this->withRuntimeBreakdown($server, $base);
    }

    /**
     * @return array{monthly: float, hourly: float, currency: string, plan: string, provider_label: string, source: string}
     */
    protected function lookupDigitalOcean(Server $server): array
    {
        $sizes = $this->cachedCatalog(
            $server,
            'digitalocean:sizes',
            fn () => (new DigitalOceanService($server->providerCredential))->getSizes()
        );

        $slug = (string) $server->size;
        $match = $this->findFirst($sizes, fn ($row) => ($row['slug'] ?? null) === $slug);

        if ($match === null) {
            throw new ProviderCostUnavailableException(
                __('DigitalOcean did not return a price for size :slug. The plan may have been retired.', ['slug' => $slug])
            );
        }

        $monthly = (float) ($match['price_monthly'] ?? 0);
        $hourly = (float) ($match['price_hourly'] ?? 0);
        $this->assertPositive($monthly, 'DigitalOcean', $slug);

        return [
            'monthly' => $monthly,
            'hourly' => $hourly > 0 ? $hourly : $monthly / self::HOURS_PER_MONTH,
            'currency' => 'USD',
            'plan' => $slug,
            'provider_label' => ServerProvider::DigitalOcean->label(),
            'source' => __('DigitalOcean catalog price'),
        ];
    }

    /**
     * @return array{monthly: float, hourly: float, currency: string, plan: string, provider_label: string, source: string}
     */
    protected function lookupHetzner(Server $server): array
    {
        $types = $this->cachedCatalog(
            $server,
            'hetzner:server_types',
            fn () => (new HetznerService($server->providerCredential))->getServerTypes()
        );

        $name = (string) $server->size;
        $region = (string) ($server->region ?? '');
        $match = $this->findFirst($types, fn ($row) => ($row['name'] ?? null) === $name);

        if ($match === null) {
            throw new ProviderCostUnavailableException(
                __('Hetzner did not return server type :name.', ['name' => $name])
            );
        }

        $prices = is_array($match['prices'] ?? null) ? $match['prices'] : [];
        $priceRow = null;
        if ($region !== '') {
            $priceRow = $this->findFirst($prices, fn ($row) => ($row['location'] ?? null) === $region);
        }
        $priceRow ??= $prices[0] ?? null;

        if (! is_array($priceRow)) {
            throw new ProviderCostUnavailableException(
                __('Hetzner returned no pricing for :name in :region.', ['name' => $name, 'region' => $region ?: '—'])
            );
        }

        $monthly = (float) ($priceRow['price_monthly']['gross']
            ?? $priceRow['price_monthly']['net']
            ?? 0);
        $hourly = (float) ($priceRow['price_hourly']['gross']
            ?? $priceRow['price_hourly']['net']
            ?? 0);
        $this->assertPositive($monthly, 'Hetzner', $name);

        return [
            'monthly' => $monthly,
            'hourly' => $hourly > 0 ? $hourly : $monthly / self::HOURS_PER_MONTH,
            'currency' => 'EUR',
            'plan' => $name,
            'provider_label' => ServerProvider::Hetzner->label(),
            'source' => __('Hetzner catalog price (gross, :region)', ['region' => $region ?: 'default location']),
        ];
    }

    /**
     * @return array{monthly: float, hourly: float, currency: string, plan: string, provider_label: string, source: string}
     */
    protected function lookupVultr(Server $server): array
    {
        $plans = $this->cachedCatalog(
            $server,
            'vultr:plans',
            fn () => (new VultrService($server->providerCredential))->getPlans()
        );

        $id = (string) $server->size;
        $match = $this->findFirst($plans, fn ($row) => ($row['id'] ?? null) === $id);

        if ($match === null) {
            throw new ProviderCostUnavailableException(
                __('Vultr did not return plan :id.', ['id' => $id])
            );
        }

        $monthly = (float) ($match['monthly_cost'] ?? 0);
        $hourly = (float) ($match['hourly_cost'] ?? 0);
        $this->assertPositive($monthly, 'Vultr', $id);

        return [
            'monthly' => $monthly,
            'hourly' => $hourly > 0 ? $hourly : $monthly / self::HOURS_PER_MONTH,
            'currency' => 'USD',
            'plan' => $id,
            'provider_label' => ServerProvider::Vultr->label(),
            'source' => __('Vultr catalog price'),
        ];
    }

    /**
     * @return array{monthly: float, hourly: float, currency: string, plan: string, provider_label: string, source: string}
     */
    protected function lookupEquinixMetal(Server $server): array
    {
        $plans = $this->cachedCatalog(
            $server,
            'equinix_metal:plans',
            fn () => (new EquinixMetalService($server->providerCredential))->getPlans()
        );

        $slug = (string) $server->size;
        $match = $this->findFirst($plans, fn ($row) => ($row['slug'] ?? null) === $slug
            || ($row['name'] ?? null) === $slug);

        if ($match === null) {
            throw new ProviderCostUnavailableException(
                __('Equinix Metal did not return plan :slug.', ['slug' => $slug])
            );
        }

        $hourly = (float) ($match['pricing']['hour'] ?? 0);
        if ($hourly <= 0) {
            throw new ProviderCostUnavailableException(
                __('Equinix Metal returned no public hourly price for :slug — this plan is likely quote-only.', ['slug' => $slug])
            );
        }

        $monthly = $hourly * self::HOURS_PER_MONTH;

        return [
            'monthly' => $monthly,
            'hourly' => $hourly,
            'currency' => 'USD',
            'plan' => $slug,
            'provider_label' => ServerProvider::EquinixMetal->label(),
            'source' => __('Equinix Metal catalog hourly × :hours', ['hours' => (int) self::HOURS_PER_MONTH]),
        ];
    }

    /**
     * Adds runtime-derived MTD/YTD figures and a human-readable summary.
     *
     * @param  array{monthly: float, hourly: float, currency: string, plan: string, provider_label: string, source: string}  $base
     * @return array{
     *   monthly: float,
     *   hourly: float,
     *   currency: string,
     *   plan: string,
     *   provider_label: string,
     *   source: string,
     *   fetched_at: CarbonImmutable,
     *   formatted: string,
     *   mtd: float,
     *   ytd: float,
     *   runtime_hours_month: float,
     *   runtime_hours_year: float,
     * }
     */
    protected function withRuntimeBreakdown(Server $server, array $base): array
    {
        $fetchedAt = CarbonImmutable::now();
        $createdAt = $server->created_at
            ? CarbonImmutable::parse($server->created_at)
            : $fetchedAt;

        $monthStart = $fetchedAt->startOfMonth();
        $yearStart = $fetchedAt->startOfYear();

        $monthHours = $this->hoursActiveBetween($createdAt, $monthStart, $fetchedAt);
        $yearHours = $this->hoursActiveBetween($createdAt, $yearStart, $fetchedAt);

        $mtd = round($base['hourly'] * $monthHours, 2);
        $ytd = round($base['hourly'] * $yearHours, 2);

        $symbol = $base['currency'] === 'USD' ? '$' : '';
        $suffix = $base['currency'] === 'USD' ? '' : ' '.$base['currency'];

        $formatted = sprintf(
            '~%s%s/mo · %s %s (catalog price, fetched %s)%s',
            $symbol,
            number_format($base['monthly'], 2),
            $base['provider_label'],
            $base['plan'],
            $fetchedAt->toDateString(),
            $suffix
        );

        return [
            ...$base,
            'fetched_at' => $fetchedAt,
            'formatted' => $formatted,
            'mtd' => $mtd,
            'ytd' => $ytd,
            'runtime_hours_month' => round($monthHours, 1),
            'runtime_hours_year' => round($yearHours, 1),
        ];
    }

    protected function hoursActiveBetween(
        CarbonImmutable $createdAt,
        CarbonImmutable $windowStart,
        CarbonImmutable $now
    ): float {
        $effectiveStart = $createdAt->greaterThan($windowStart) ? $createdAt : $windowStart;
        if ($effectiveStart->greaterThanOrEqualTo($now)) {
            return 0.0;
        }

        return max(0.0, $effectiveStart->diffInMinutes($now) / 60.0);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $fetcher
     * @return TResult
     */
    protected function cachedCatalog(Server $server, string $tag, callable $fetcher): mixed
    {
        $key = sprintf('cost-estimator:%s:cred:%d', $tag, $server->providerCredential->id);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, $fetcher);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  callable(array<string, mixed>): bool  $predicate
     * @return array<string, mixed>|null
     */
    protected function findFirst(array $rows, callable $predicate): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && $predicate($row)) {
                return $row;
            }
        }

        return null;
    }

    protected function assertPositive(float $value, string $providerLabel, string $plan): void
    {
        if ($value <= 0.0) {
            throw new ProviderCostUnavailableException(
                __(':provider returned no monthly price for :plan.', [
                    'provider' => $providerLabel,
                    'plan' => $plan,
                ])
            );
        }
    }
}
