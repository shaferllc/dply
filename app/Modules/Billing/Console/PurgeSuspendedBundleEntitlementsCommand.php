<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use App\Models\OrganizationBundleEntitlement;
use App\Modules\Billing\Services\BundleEntitlementSynchronizer;
use Illuminate\Console\Command;

/**
 * Hard-purges bundle workspaces whose suspension has aged past
 * config('bundle.retention_days') — the tail of the grace → suspend → delete
 * lifecycle (Q4). Emits the terminal `bundle.deleted` transition per org.
 * Idempotent; intended to run daily on a schedule.
 *
 *   php artisan dply:bundle:purge
 */
final class PurgeSuspendedBundleEntitlementsCommand extends Command
{
    protected $signature = 'dply:bundle:purge';

    protected $description = 'Purge bundled-products workspaces suspended past the retention window.';

    public function handle(BundleEntitlementSynchronizer $synchronizer): int
    {
        if (! config('bundle.enabled', false)) {
            $this->warn('Bundle perk is dark (BUNDLE_PRODUCTS_ENABLED=false) — nothing to purge.');

            return self::SUCCESS;
        }

        $purged = 0;

        OrganizationBundleEntitlement::query()
            ->where('status', OrganizationBundleEntitlement::STATUS_SUSPENDED)
            ->with('organization')
            ->chunkById(200, function ($rows) use ($synchronizer, &$purged): void {
                foreach ($rows as $row) {
                    $org = $row->organization ?? Organization::query()->find($row->organization_id);
                    if ($org !== null && $synchronizer->purgeExpired($org)) {
                        $purged++;
                    }
                }
            });

        $this->info("Purged {$purged} suspended bundle entitlement(s) past retention.");

        return self::SUCCESS;
    }
}
