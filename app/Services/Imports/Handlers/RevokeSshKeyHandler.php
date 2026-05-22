<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ProviderCredential;
use App\Services\Imports\ImportDriver;
use App\Services\Imports\SourceDriverFactory;
use App\Services\Imports\StepHandler;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Symmetric to PushSshKeyHandler: deletes the ephemeral key from the source
 * server via the driver. Runs unconditionally as the final step (per Q5),
 * including on aborted/failed paths — the user's ssh-key surface should be
 * back to its prior state regardless of the migration outcome.
 *
 * Idempotent: if no source key id is recorded, the step is a no-op success
 * (either nothing was pushed, or a prior revoke succeeded).
 */
class RevokeSshKeyHandler implements StepHandler
{
    public function __construct(protected SourceDriverFactory $drivers) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_REVOKE_SSH_KEY;
    }

    public function execute(ImportMigrationStep $step): void
    {
        $migration = ImportServerMigration::find($step->import_server_migration_id);
        if ($migration === null) {
            throw new RuntimeException('Parent migration disappeared before revoke_ssh_key ran.');
        }

        if ($migration->ssh_key_source_id === null || $migration->ssh_key_revoked_at !== null) {
            return;
        }

        $credential = ProviderCredential::find($migration->provider_credential_id);
        if ($credential === null) {
            throw new RuntimeException('Provider credential missing for migration '.$migration->id);
        }

        $driver = $this->driverFor($credential);
        $driver->revokeSshKey($migration->source_server_id, $migration->ssh_key_source_id);

        $migration->ssh_key_revoked_at = Carbon::now();
        $migration->save();
    }

    protected function driverFor(ProviderCredential $credential): ImportDriver
    {
        return $this->drivers->for($credential);
    }
}
