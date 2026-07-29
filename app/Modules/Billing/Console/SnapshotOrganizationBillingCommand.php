<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use App\Modules\Billing\Services\OrganizationBillingSnapshotWriter;
use Illuminate\Console\Command;

class SnapshotOrganizationBillingCommand extends Command
{
    protected $signature = 'dply:billing:snapshot-organizations
                            {--date= : Snapshot date (Y-m-d), defaults to today}
                            {--organization= : Optional organization ULID to snapshot}
                            {--dry-run : Show target organizations without writing snapshots}';

    protected $description = 'Persist a daily billing snapshot for organizations.';

    public function handle(OrganizationBillingSnapshotWriter $snapshotWriter): int
    {
        $dateInput = $this->option('date');
        $snapshotDate = is_string($dateInput) && $dateInput !== ''
            ? now()->parse($dateInput)->startOfDay()
            : now()->startOfDay();

        $organizationId = $this->option('organization');
        $organizations = Organization::query()
            ->when(
                is_string($organizationId) && $organizationId !== '',
                fn ($query) => $query->where('id', $organizationId),
            )
            ->orderBy('created_at')
            ->get(['id', 'name']);

        if ($organizations->isEmpty()) {
            $this->warn(__('No organizations matched the requested scope.'));

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            foreach ($organizations as $organization) {
                $this->line(__('Dry run: would snapshot :org (:id).', [
                    'org' => $organization->name,
                    'id' => $organization->id,
                ]));
            }
            $this->info(__('Dry run complete for :count organization(s).', ['count' => $organizations->count()]));

            return self::SUCCESS;
        }

        foreach ($organizations as $organization) {
            $snapshotWriter->writeForOrganization($organization, $snapshotDate);
        }

        $this->info(__('Persisted billing snapshots for :count organization(s) on :date.', [
            'count' => $organizations->count(),
            'date' => $snapshotDate->toDateString(),
        ]));

        return self::SUCCESS;
    }
}
