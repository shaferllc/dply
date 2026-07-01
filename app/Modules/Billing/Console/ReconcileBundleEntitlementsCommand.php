<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Enums\BundleTransition;
use App\Models\Organization;
use App\Modules\Billing\Services\BundleEntitlementSynchronizer;
use Illuminate\Console\Command;

/**
 * Re-asserts every org's bundle entitlement against the live predicate — the
 * pull/reconcile safety net (Q6) AND the one-time backfill (Q10). Idempotent, so
 * it's safe to run on launch to provision existing qualifiers and on a nightly
 * schedule to heal any missed `bundle.*` webhook.
 *
 *   php artisan dply:bundle:reconcile
 */
final class ReconcileBundleEntitlementsCommand extends Command
{
    protected $signature = 'dply:bundle:reconcile';

    protected $description = 'Reconcile every organization\'s bundled-products entitlement (backfill + drift heal).';

    public function handle(BundleEntitlementSynchronizer $synchronizer): int
    {
        if (! config('bundle.enabled', false)) {
            $this->warn('Bundle perk is dark (BUNDLE_PRODUCTS_ENABLED=false) — nothing to reconcile.');

            return self::SUCCESS;
        }

        $counts = ['provisioned' => 0, 'suspended' => 0, 'resumed' => 0, 'unchanged' => 0];

        Organization::query()->chunkById(200, function ($orgs) use ($synchronizer, &$counts): void {
            foreach ($orgs as $org) {
                $transition = $synchronizer->sync($org);
                $key = match ($transition) {
                    BundleTransition::Provisioned => 'provisioned',
                    BundleTransition::Suspended => 'suspended',
                    BundleTransition::Resumed => 'resumed',
                    default => 'unchanged',
                };
                $counts[$key]++;
            }
        });

        $this->table(
            ['provisioned', 'suspended', 'resumed', 'unchanged'],
            [[$counts['provisioned'], $counts['suspended'], $counts['resumed'], $counts['unchanged']]],
        );

        return self::SUCCESS;
    }
}
