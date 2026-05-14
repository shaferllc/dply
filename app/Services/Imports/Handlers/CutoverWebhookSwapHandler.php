<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiImportDriver;
use App\Services\Imports\StepHandler;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cutover step #4: disable any Ploi-side deploy webhooks so a future push to
 * the repo doesn't trigger a Ploi deploy on the (now-cut-over) Ploi site.
 * dply's own deploy webhook on the new dply site is already wired up during
 * site creation, so we just need to retire the Ploi side.
 *
 * Best-effort: failures here don't roll back cutover. The user can manually
 * disable from Ploi if needed. result_data tracks what we attempted.
 */
class CutoverWebhookSwapHandler implements StepHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_CUTOVER_WEBHOOK_SWAP;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('cutover_webhook_swap requires a site-scoped step.');
        }
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null) {
            throw new RuntimeException('Child migration missing.');
        }
        $migration = ImportServerMigration::find($child->import_server_migration_id);
        if ($migration === null) {
            throw new RuntimeException('Parent migration missing.');
        }
        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing.');
        }

        $driver = PloiImportDriver::for($credential);
        $webhooks = $driver->listSiteWebhooks($migration->source_server_id, $child->source_site_id);

        $deleted = 0;
        $failures = 0;
        foreach ($webhooks as $hook) {
            try {
                $driver->deleteSiteWebhook($migration->source_server_id, $child->source_site_id, $hook['id']);
                $deleted++;
            } catch (\Throwable $e) {
                $failures++;
                Log::warning('webhook delete failed on Ploi side', [
                    'webhook_id' => $hook['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $step->result_data = [
            'webhooks_attempted' => count($webhooks),
            'webhooks_removed' => $deleted,
            'failures' => $failures,
        ];
        $step->save();
    }
}
