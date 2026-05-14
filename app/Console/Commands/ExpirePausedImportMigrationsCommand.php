<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiImportDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Per Q17: when a migration is paused without progress for 7 days, the
 * trust-window of the ephemeral SSH key has run long enough — revoke the
 * key and mark the migration `expired` so the user has to re-confirm to
 * proceed. The 168-hour window matches the design's stated cadence
 * (72h email nudge handled separately; this is the irreversible safety
 * net).
 *
 * "Paused" here means: migration is in a non-terminal status (staging,
 * cutover_in_progress, etc.) AND its newest step row hasn't been touched
 * in 168h.
 */
class ExpirePausedImportMigrationsCommand extends Command
{
    protected $signature = 'dply:imports:expire-paused
                            {--hours=168 : Stale-pause threshold in hours (default 168 = 7 days)}
                            {--dry-run : Report without revoking keys or mutating state}';

    protected $description = 'Auto-revoke ephemeral SSH keys for migrations paused beyond the trust window (Q17).';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        if ($hours < 24) {
            $this->error('Threshold must be at least 24 hours to avoid expiring active migrations.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $threshold = Carbon::now()->subHours($hours);

        $terminal = [
            ImportServerMigration::STATUS_COMPLETED,
            ImportServerMigration::STATUS_PARTIAL,
            ImportServerMigration::STATUS_ABORTED,
            ImportServerMigration::STATUS_EXPIRED,
        ];

        $candidates = ImportServerMigration::query()
            ->whereNotIn('status', $terminal)
            ->whereNotNull('ssh_key_pushed_at')
            ->whereNull('ssh_key_revoked_at')
            ->get();

        $expired = 0;
        foreach ($candidates as $migration) {
            $latestActivity = $this->latestStepActivity($migration);
            if ($latestActivity !== null && $latestActivity->isAfter($threshold)) {
                continue;
            }
            $this->expireOne($migration, $dryRun);
            $expired++;
        }

        $this->info(sprintf(
            '%s %d paused migrations exceeded the %dh threshold.',
            $dryRun ? '[dry-run]' : 'Expired',
            $expired,
            $hours,
        ));

        return self::SUCCESS;
    }

    protected function latestStepActivity(ImportServerMigration $migration): ?Carbon
    {
        $latest = ImportMigrationStep::query()
            ->where('import_server_migration_id', $migration->id)
            ->whereNotNull('finished_at')
            ->max('finished_at');
        if (is_string($latest) && $latest !== '') {
            return Carbon::parse($latest);
        }
        // Fallback to push-time when no step has finished yet.
        return $migration->ssh_key_pushed_at;
    }

    protected function expireOne(ImportServerMigration $migration, bool $dryRun): void
    {
        $this->line(sprintf(
            '%s migration %s — last activity beyond threshold; revoking key.',
            $dryRun ? '[dry-run]' : 'Expiring',
            $migration->id,
        ));

        if ($dryRun) {
            return;
        }

        // Revoke the key directly via the driver (rather than queuing the
        // revoke_ssh_key step) so the trust window is closed immediately
        // even if the queue is backlogged.
        try {
            $credential = ProviderCredential::find($migration->provider_credential_id);
            if ($credential !== null && $migration->ssh_key_source_id !== null) {
                match ($migration->source) {
                    'ploi' => PloiImportDriver::for($credential)
                        ->revokeSshKey($migration->source_server_id, $migration->ssh_key_source_id),
                    'forge' => \App\Services\Imports\Forge\ForgeImportDriver::for($credential)
                        ->revokeSshKey($migration->source_server_id, $migration->ssh_key_source_id),
                    default => null,
                };
            }
            $migration->ssh_key_revoked_at = Carbon::now();
        } catch (\Throwable $e) {
            // Even if revoke fails (key already gone, credential removed, etc.),
            // mark the migration expired and log — the worst case is a stale key
            // we can't tear down, but the trust-window state on dply's side is
            // closed.
            Log::warning('SSH key revoke failed during expire-paused', [
                'migration_id' => $migration->id,
                'error' => $e->getMessage(),
            ]);
        }

        $migration->status = ImportServerMigration::STATUS_EXPIRED;
        $migration->completed_at = Carbon::now();
        $migration->failure_summary = sprintf('Auto-expired after %dh of pause inactivity (Q17 trust-window enforcement).', (int) $this->option('hours'));
        $migration->save();

        // Cascade-abort any pending steps so they don't try to run later if the
        // queue catches up.
        ImportMigrationStep::query()
            ->where('import_server_migration_id', $migration->id)
            ->whereIn('status', [ImportMigrationStep::STATUS_PENDING, ImportMigrationStep::STATUS_RUNNING])
            ->update([
                'status' => ImportMigrationStep::STATUS_SKIPPED,
                'finished_at' => Carbon::now(),
                'error_message' => 'Migration expired before this step could run.',
            ]);

        ImportSiteMigration::query()
            ->where('import_server_migration_id', $migration->id)
            ->whereIn('status', [
                ImportSiteMigration::STATUS_PENDING,
                ImportSiteMigration::STATUS_STAGING,
                ImportSiteMigration::STATUS_READY_FOR_CUTOVER,
                ImportSiteMigration::STATUS_CUTOVER_IN_PROGRESS,
            ])
            ->update(['status' => ImportSiteMigration::STATUS_ABORTED]);
    }
}
