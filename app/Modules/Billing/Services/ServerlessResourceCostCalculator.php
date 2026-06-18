<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Computes the monthly **resource** cost (cents) of the DigitalOcean managed
 * databases and Redis clusters backing a set of dply-managed serverless
 * functions, with dply's markup applied. The FaaS counterpart to
 * {@see CloudResourceCostCalculator}.
 *
 * In managed mode these clusters run on dply-owned infra (dply pays DO), so the
 * raw list price must be billed back with margin on top of the flat per-function
 * fee. BYO functions provision against the customer's own account and are NOT
 * counted here. Billed via the metered Stripe line (quantity = cents).
 */
class ServerlessResourceCostCalculator
{
    /**
     * Total marked-up monthly cost, in cents, of the managed databases/caches
     * attached to the given managed function sites.
     *
     * @param  Collection<int, Site>  $managedSites  Billable dply-managed function sites.
     */
    public function subtotalCents(Collection $managedSites): int
    {
        if ($managedSites->isEmpty()) {
            return 0;
        }

        $markupPercent = max(0, (int) config('subscription.standard.serverless_markup_percent', 0));

        $total = 0;
        foreach ($managedSites as $site) {
            $config = $site->serverlessConfig();

            $dbSize = (string) (($config['database']['size'] ?? '') ?: '');
            if ($dbSize !== '') {
                $total += $this->withMarkup($this->dollarsToCents((float) config('serverless_pricing.database.'.$dbSize, 0)), $markupPercent);
            }

            $cacheSize = (string) (($config['cache']['size'] ?? '') ?: '');
            if ($cacheSize !== '') {
                $total += $this->withMarkup($this->dollarsToCents((float) config('serverless_pricing.cache.'.$cacheSize, 0)), $markupPercent);
            }
        }

        return $total;
    }

    private function dollarsToCents(float $dollars): int
    {
        return (int) round($dollars * 100);
    }

    private function withMarkup(int $rawCents, int $markupPercent): int
    {
        return (int) round($rawCents * (100 + $markupPercent) / 100);
    }
}
