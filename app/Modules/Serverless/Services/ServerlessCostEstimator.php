<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;

/**
 * Estimates the monthly cost of a serverless function — dply's flat
 * per-function fee plus the DigitalOcean resources it has provisioned.
 *
 * Used to show an upfront estimate before an operator provisions anything.
 * DigitalOcean clusters are billed by DigitalOcean directly; these are
 * estimates, surfaced so the cost is never a surprise.
 */
class ServerlessCostEstimator
{
    /** dply's flat monthly fee per serverless function, in USD. */
    public function functionFee(): float
    {
        return ((int) config('subscription.standard.serverless_cents', 0)) / 100;
    }

    /**
     * The customer-facing pricing terms for a managed function, derived from
     * the same config the biller reads so the marketing surface can never
     * quote a price the invoice contradicts. Post-allowance rates have
     * `markup_percent` already applied — they are what the customer pays, not
     * the provider cost floor.
     *
     * @return array{
     *     flat_cents: int,
     *     metered: bool,
     *     included_gib_seconds: int,
     *     included_invocations: int,
     *     included_hours_at_default_memory: int,
     *     gib_seconds_cents_per_100k: int,
     *     invocations_cents_per_million: int,
     * }
     */
    public function pricingSummary(): array
    {
        $markup = max(0, (int) config('dply.serverless.usage_billing.markup_percent', 0));
        $withMarkup = static fn (int $cents): int => (int) ceil($cents * (100 + $markup) / 100);

        $includedGibSeconds = max(0, (int) config('dply.serverless.usage_billing.included_gib_seconds_per_function', 0));

        // GiB-seconds mean nothing to most operators; hours of a default-sized
        // app is the same number in a unit they can picture.
        $defaultMemoryGib = Site::SERVERLESS_DEFAULT_MEMORY_MB / 1024;

        return [
            'flat_cents' => (int) config('subscription.standard.serverless_cents', 0),
            'metered' => (bool) config('dply.serverless.usage_billing.enabled', false),
            'included_gib_seconds' => $includedGibSeconds,
            'included_invocations' => max(0, (int) config('dply.serverless.usage_billing.included_invocations_per_function', 0)),
            'included_hours_at_default_memory' => (int) floor($includedGibSeconds / $defaultMemoryGib / 3600),
            'gib_seconds_cents_per_100k' => $withMarkup((int) config('dply.serverless.usage_billing.gib_seconds_cents_per_100k', 0)),
            'invocations_cents_per_million' => $withMarkup((int) config('dply.serverless.usage_billing.invocations_cents_per_million', 0)),
        ];
    }

    public function databaseMonthly(string $size): float
    {
        return (float) config('serverless_pricing.database.'.$size, 0);
    }

    public function cacheMonthly(string $size): float
    {
        return (float) config('serverless_pricing.cache.'.$size, 0);
    }

    /**
     * Full monthly estimate for a function — dply's fee plus every
     * DigitalOcean resource it currently has provisioned.
     *
     * @return array{lines: list<array{label: string, amount: float, billed_by: string}>, total: float}
     */
    public function forSite(Site $site): array
    {
        $config = $site->serverlessConfig();

        $lines = [[
            'label' => 'Function',
            'amount' => $this->functionFee(),
            'billed_by' => 'dply',
        ]];

        $database = is_array($config['database'] ?? null) ? $config['database'] : [];
        if (($database['size'] ?? '') !== '') {
            $lines[] = [
                'label' => 'Managed database',
                'amount' => $this->databaseMonthly((string) $database['size']),
                'billed_by' => 'DigitalOcean',
            ];
        }

        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        if (($cache['size'] ?? '') !== '') {
            $lines[] = [
                'label' => 'Managed Redis',
                'amount' => $this->cacheMonthly((string) $cache['size']),
                'billed_by' => 'DigitalOcean',
            ];
        }

        return [
            'lines' => $lines,
            'total' => (float) array_sum(array_column($lines, 'amount')),
        ];
    }
}
