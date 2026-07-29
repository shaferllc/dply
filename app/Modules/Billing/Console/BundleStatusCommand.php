<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use App\Models\OrganizationBundleEntitlement;
use Illuminate\Console\Command;

/**
 * Read-only snapshot of the bundled-products perk: who qualifies, what's
 * provisioned, and any drift between the live predicate and the persisted
 * state (which `dply:bundle:reconcile` would resolve). Safe to run anytime —
 * mutates nothing. See docs/adr/bundled-products-sso.md.
 *
 *   php artisan dply:bundle:status
 */
final class BundleStatusCommand extends Command
{
    protected $signature = 'dply:bundle:status {--drift-only : Only list orgs whose live entitlement disagrees with stored state.}';

    protected $description = 'Show bundled-products entitlement state + drift (read-only).';

    public function handle(): int
    {
        $this->line('Bundle perk: '.(config('bundle.enabled', false) ? '<info>ENABLED</info>' : '<comment>dark (BUNDLE_PRODUCTS_ENABLED=false)</comment>'));

        $rows = OrganizationBundleEntitlement::query()->get()->keyBy('organization_id');
        $counts = ['active' => 0, 'suspended' => 0, 'deleted' => 0, 'qualifying' => 0, 'drift' => 0];
        $drift = [];

        Organization::query()->chunkById(200, function ($orgs) use ($rows, &$counts, &$drift): void {
            foreach ($orgs as $org) {
                $qualifies = $org->qualifiesForBundledProducts();
                $status = $rows[$org->id]->status ?? null;

                if ($qualifies) {
                    $counts['qualifying']++;
                }
                if ($status !== null && isset($counts[$status])) {
                    $counts[$status]++;
                }

                // Drift = the reconcile would act: qualifies but not active, or
                // active but no longer qualifying.
                $active = $status === OrganizationBundleEntitlement::STATUS_ACTIVE;
                if ($qualifies !== $active) {
                    $counts['drift']++;
                    $drift[] = [
                        'org' => $org->name,
                        'id' => $org->id,
                        'qualifies' => $qualifies ? 'yes' : 'no',
                        'stored' => $status ?? '—',
                        'action' => $qualifies ? 'provision/resume' : 'suspend',
                    ];
                }
            }
        });

        if (! $this->option('drift-only')) {
            $this->table(
                ['qualifying', 'active', 'suspended', 'deleted', 'drift'],
                [[$counts['qualifying'], $counts['active'], $counts['suspended'], $counts['deleted'], $counts['drift']]],
            );
        }

        if ($drift !== []) {
            $this->warn(count($drift).' org(s) drift — `dply:bundle:reconcile` would reconcile:');
            $this->table(['org', 'id', 'qualifies', 'stored', 'reconcile action'], $drift);
        } else {
            $this->info('No drift — stored state matches live entitlement.');
        }

        return self::SUCCESS;
    }
}
