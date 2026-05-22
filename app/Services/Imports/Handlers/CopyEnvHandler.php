<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Imports\StepHandler;
use RuntimeException;

/**
 * Fetches the source site's .env from the import driver and stores it on the
 * dply Site row (`env_file_content`, encrypted column). dply's existing
 * SiteEnvPusher is the mechanism that later pushes the content onto the
 * target server's filesystem; this handler only mediates the import.
 *
 * Idempotent — re-runs overwrite with the latest Ploi state.
 */
class CopyEnvHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_COPY_ENV;
    }

    public function execute(ImportMigrationStep $step): void
    {
        [$child, $migration, $site] = $this->resolve($step);
        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing for migration.');
        }

        $driver = app(\App\Services\Imports\SourceDriverFactory::class)->for($credential);
        $envContent = $driver->fetchEnv($migration->source_server_id, $child->source_site_id);

        $site->env_file_content = $envContent;
        // env_cache_origin column is varchar(16); keep within budget.
        $site->env_cache_origin = 'import:'.$child->source;
        $site->save();

        $step->result_data = ['bytes' => strlen($envContent)];
        $step->save();
    }

    /**
     * @return array{0: ImportSiteMigration, 1: ImportServerMigration, 2: Site}
     */
    protected function resolve(ImportMigrationStep $step): array
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('copy_env requires a site-scoped step.');
        }
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null) {
            throw new RuntimeException('Child migration missing.');
        }
        if ($child->target_site_id === null) {
            throw new RuntimeException('copy_env runs after create_target_site; no target_site_id set.');
        }
        $migration = ImportServerMigration::find($child->import_server_migration_id);
        if ($migration === null) {
            throw new RuntimeException('Parent migration missing.');
        }
        $site = Site::find($child->target_site_id);
        if ($site === null) {
            throw new RuntimeException('Target dply Site missing.');
        }

        return [$child, $migration, $site];
    }
}
