<?php

declare(strict_types=1);

namespace App\Actions\Realtime;

use App\Jobs\ProvisionRealtimeAppJob;
use App\Models\RealtimeApp;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Services\Billing\StripeSubscriptionSyncer;
use InvalidArgumentException;

/**
 * Changes a managed realtime app's connection tier. Persists the new tier +
 * connection cap, then re-publishes the credential record so the relay enforces
 * the new {@see RealtimeApp::maxConnections()} cap. Billing reconciles on its
 * own: the org's per-tier app counts feed {@see OrganizationBillingStateComputer},
 * and {@see StripeSubscriptionSyncer} moves the Stripe line
 * to the new tier on the next sync.
 */
class UpdateRealtimeApp
{
    public function changeTier(RealtimeApp $app, string $tier): RealtimeApp
    {
        $tiers = (array) config('realtime.tiers', []);
        if (! array_key_exists($tier, $tiers)) {
            throw new InvalidArgumentException(__('Unknown broadcasting tier.'));
        }

        // No-op when the tier is unchanged — avoids a needless relay round-trip
        // and a no-change Stripe sync.
        if ($tier === $app->tierSlug()) {
            return $app;
        }

        $app->forceFill([
            'tier' => $tier,
            'max_connections' => (int) ($tiers[$tier]['max_connections']
                ?? config('realtime.plan.max_connections')),
        ])->save();

        // Re-sync the credential record (which carries maxConnections) to the
        // relay off-request. Idempotent and safe to retry.
        ProvisionRealtimeAppJob::dispatch($app->id);

        return $app;
    }
}
