<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Models\ForgeServer;
use App\Models\ForgeSite;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the full declared-upfront step plan for a Ploi-server migration
 * (Q7 design: plan visible at t=0, no surprise step growth). The planner
 * persists three layers in one transaction: an ImportServerMigration parent,
 * one ImportSiteMigration child per included site, and ImportMigrationStep
 * rows hanging off the parent (server-scoped steps) or each child (per-site).
 *
 * It does NOT execute anything. The step rows land in `pending` status; a
 * downstream orchestrator job picks them up in sequence and marks each
 * running → succeeded | failed | skipped.
 *
 * Cutover steps are part of the same plan but reserved for explicit user
 * trigger — the orchestrator pauses at `ready_for_cutover` per Q4 + Q9b.
 */
class MigrationPlanner
{
    public const STAGING_STEPS = [
        ImportMigrationStep::KEY_FREEZE_SNAPSHOT,
        ImportMigrationStep::KEY_CREATE_TARGET_SITE,
        ImportMigrationStep::KEY_CLONE_REPO,
        ImportMigrationStep::KEY_COPY_ENV,
        ImportMigrationStep::KEY_DUMP_DB,
        ImportMigrationStep::KEY_RESTORE_DB,
        ImportMigrationStep::KEY_RECREATE_CRONS,
        ImportMigrationStep::KEY_RECREATE_DAEMONS,
        ImportMigrationStep::KEY_RECREATE_SCHEDULER,
        ImportMigrationStep::KEY_SETUP_SSL,
    ];

    public const CUTOVER_STEPS = [
        ImportMigrationStep::KEY_CUTOVER_MAINTENANCE_ON,
        ImportMigrationStep::KEY_CUTOVER_DB_DELTA,
        ImportMigrationStep::KEY_CUTOVER_DNS_SWAP,
        ImportMigrationStep::KEY_CUTOVER_WEBHOOK_SWAP,
        ImportMigrationStep::KEY_CUTOVER_SMOKE_TEST,
    ];

    /**
     * @param  PloiServer|ForgeServer  $source  the inventory-side row for the source server
     * @param  array<string, mixed> $selectedSiteIds  ulid PKs from the source-side sites table for the user-checked sites
     * @param  string  $targetServerId  ulid PK of the dply Server provisioned for this migration
     * @param  ProviderCredential  $credential  the credential used for API calls
     */
    public function plan(
        PloiServer|ForgeServer $source,
        array $selectedSiteIds,
        string $targetServerId,
        ProviderCredential $credential,
        string $userId,
    ): ImportServerMigration {
        $sourceKey = match (true) {
            $source instanceof PloiServer => 'ploi',
            $source instanceof ForgeServer => 'forge',
        };
        if ($credential->provider !== $sourceKey) {
            throw new RuntimeException(sprintf(
                'MigrationPlanner expects a %s ProviderCredential for a %s source.',
                $sourceKey,
                $sourceKey,
            ));
        }

        /** @var Collection<int, PloiSite|ForgeSite> $selectedSites */
        $selectedSites = $source instanceof PloiServer
            ? PloiSite::query()
                ->where('ploi_server_id', $source->id)
                ->whereIn('id', $selectedSiteIds)
                ->orderBy('domain')
                ->get()
            : ForgeSite::query()
                ->where('forge_server_id', $source->id)
                ->whereIn('id', $selectedSiteIds)
                ->orderBy('domain')
                ->get();

        if ($selectedSites->isEmpty()) {
            throw new RuntimeException('Migration plan requires at least one selected site.');
        }
        foreach ($selectedSites as $site) {
            if (! $site->isMigrationEligible()) {
                throw new RuntimeException(sprintf(
                    'Site %s is %s; not eligible for v1 migration.',
                    $site->domain,
                    $site->site_type,
                ));
            }
        }

        return DB::transaction(function () use ($source, $selectedSites, $targetServerId, $credential, $userId, $sourceKey): ImportServerMigration {
            $orgId = $credential->organization_id;
            if (! is_string($orgId) || $orgId === '') {
                throw new RuntimeException($sourceKey.' credential is missing an organization scope.');
            }

            $parent = ImportServerMigration::create([
                'organization_id' => $orgId,
                'user_id' => $userId,
                'provider_credential_id' => $credential->id,
                'source' => $sourceKey,
                'source_server_id' => $source->source_id,
                'target_server_id' => $targetServerId,
                'status' => ImportServerMigration::STATUS_PENDING,
            ]);

            $sequence = 0;
            $this->appendServerStep($parent->id, ++$sequence, ImportMigrationStep::KEY_PUSH_SSH_KEY);
            $this->appendServerStep($parent->id, ++$sequence, ImportMigrationStep::KEY_ELIGIBILITY_SCAN);

            foreach ($selectedSites as $site) {
                $child = ImportSiteMigration::create([
                    'import_server_migration_id' => $parent->id,
                    'source' => $sourceKey,
                    'source_site_id' => $site->source_id,
                    'domain' => $site->domain,
                    'site_type' => $site->site_type,
                    'status' => ImportSiteMigration::STATUS_PENDING,
                    'source_snapshot' => $site->source_snapshot ?? [],
                ]);

                foreach (self::STAGING_STEPS as $key) {
                    $this->appendSiteStep($parent->id, $child->id, ++$sequence, $key);
                }
                foreach (self::CUTOVER_STEPS as $key) {
                    $this->appendSiteStep($parent->id, $child->id, ++$sequence, $key);
                }
            }

            $this->appendServerStep($parent->id, ++$sequence, ImportMigrationStep::KEY_COLLECT_MANUAL_REVIEW);
            $this->appendServerStep($parent->id, ++$sequence, ImportMigrationStep::KEY_REVOKE_SSH_KEY);

            return $parent->fresh(['siteMigrations.steps', 'steps']);
        });
    }

    protected function appendServerStep(string $parentId, int $sequence, string $key): void
    {
        ImportMigrationStep::create([
            'import_server_migration_id' => $parentId,
            'import_site_migration_id' => null,
            'sequence' => $sequence,
            'step_key' => $key,
            'status' => ImportMigrationStep::STATUS_PENDING,
            'attempts' => 0,
        ]);
    }

    protected function appendSiteStep(string $parentId, string $childId, int $sequence, string $key): void
    {
        ImportMigrationStep::create([
            'import_server_migration_id' => $parentId,
            'import_site_migration_id' => $childId,
            'sequence' => $sequence,
            'step_key' => $key,
            'status' => ImportMigrationStep::STATUS_PENDING,
            'attempts' => 0,
        ]);
    }
}
