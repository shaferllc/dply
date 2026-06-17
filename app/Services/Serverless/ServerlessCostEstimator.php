<?php

declare(strict_types=1);

namespace App\Services\Serverless;

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
    /** @return array<string, mixed> */
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
