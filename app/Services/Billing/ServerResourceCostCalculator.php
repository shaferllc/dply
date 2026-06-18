<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Computes the monthly all-in cost, in cents, of dply-managed servers.
 *
 * Managed VMs run on dply-owned Hetzner infrastructure (dply pays Hetzner), so
 * each is billed provider-cost × markup as a single line and does NOT count
 * toward the per-server plan tier. The raw Hetzner monthly price per server_type
 * slug and the markup live in config/subscription.php.
 *
 * Returns cents. Billed via a metered Stripe line (quantity = cents), mirroring
 * the Cloud / Edge usage lines.
 */
class ServerResourceCostCalculator
{
    /**
     * Total marked-up monthly cost, in cents, of the given dply-managed servers.
     *
     * @param  Collection<int, Server>  $managedServers
     */
    public function subtotalCents(Collection $managedServers): int
    {
        if ($managedServers->isEmpty()) {
            return 0;
        }

        $markupPercent = max(0, (int) config('subscription.standard.managed_server_markup_percent', 0));
        $rates = (array) config('subscription.standard.managed_server_cents', []);

        $total = 0;
        foreach ($managedServers as $server) {
            $total += $this->withMarkup($this->rate($rates, (string) $server->size), $markupPercent);
        }

        return $total;
    }

    /**
     * Marked-up monthly price for a single size slug — used by the create UI to
     * preview the all-in price before provisioning.
     */
    public function monthlyCentsForSize(string $size): int
    {
        $markupPercent = max(0, (int) config('subscription.standard.managed_server_markup_percent', 0));
        $rates = (array) config('subscription.standard.managed_server_cents', []);

        return $this->withMarkup($this->rate($rates, $size), $markupPercent);
    }

    /**
     * Look up a raw provider rate for a size slug, falling back to the cheapest
     * known size so an unrecognized slug never bills $0.
     *
     * @param  array<string, mixed> $rates
     */
    private function rate(array $rates, string $size): int
    {
        if (isset($rates[$size])) {
            return (int) $rates[$size];
        }

        $values = array_map('intval', array_values($rates));

        return $values === [] ? 0 : min($values);
    }

    private function withMarkup(int $rawCents, int $markupPercent): int
    {
        return (int) round($rawCents * (100 + $markupPercent) / 100);
    }
}
