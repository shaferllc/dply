<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\Imports\Forge\ForgeImportDriver;
use App\Services\Imports\Ploi\PloiImportDriver;
use App\Services\Notifications\NotificationPublisher;
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
                            {--nudge-hours=72 : Send the action-required nudge after this many paused hours}
                            {--dry-run : Report without revoking keys or mutating state}';

    protected $description = 'Send 72h paused-migration nudges, then revoke ephemeral SSH keys at 168h (Q17 trust window).';

    public function handle(NotificationPublisher $publisher): int
    {
        $hours = (int) $this->option('hours');
        $nudgeHours = (int) $this->option('nudge-hours');
        if ($hours < 24) {
            $this->error('Threshold must be at least 24 hours to avoid expiring active migrations.');

            return self::FAILURE;
        }
        if ($nudgeHours >= $hours) {
            $this->error('Nudge threshold must be less than the expiry threshold.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $expiryThreshold = Carbon::now()->subHours($hours);
        $nudgeThreshold = Carbon::now()->subHours($nudgeHours);

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
        $nudged = 0;
        foreach ($candidates as $migration) {
            $latestActivity = $this->latestStepActivity($migration);

            if ($latestActivity === null || $latestActivity->isBefore($expiryThreshold)) {
                $this->expireOne($migration, $dryRun);
                $expired++;

                continue;
            }

            if ($latestActivity->isBefore($nudgeThreshold)
                && $migration->paused_nudge_sent_at === null
            ) {
                $this->nudgeOne($migration, $publisher, $dryRun, $hours);
                $nudged++;
            }
        }

        $this->info(sprintf(
            '%s %d migrations expired, %d nudged (thresholds: %dh/%dh).',
            $dryRun ? '[dry-run]' : 'Run:',
            $expired,
            $nudged,
            $nudgeHours,
            $hours,
        ));

        return self::SUCCESS;
    }

    /**
     * Emit the action-required 72h nudge notification, once per migration.
     */
    protected function nudgeOne(ImportServerMigration $migration, NotificationPublisher $publisher, bool $dryRun, int $expiryHours): void
    {
        $this->line(sprintf(
            '%s nudging migration %s — paused past nudge threshold.',
            $dryRun ? '[dry-run]' : 'Nudging',
            $migration->id,
        ));

        if ($dryRun) {
            return;
        }

        try {
            $actor = User::find($migration->user_id);
            $publisher->publish(
                eventKey: 'import.migration.paused_nudge',
                subject: $migration,
                title: __('Migration paused — auto-revoke in <:remaining h', ['remaining' => $expiryHours]),
                body: __('Resume the migration to keep the ephemeral SSH key alive, or abort to revoke immediately. After :hours h of inactivity dply will auto-revoke the key.', ['hours' => $expiryHours]),
                url: route('imports.ploi.migration.progress', $migration),
                metadata: [
                    'migration_id' => $migration->id,
                    'expiry_hours' => $expiryHours,
                ],
                actor: $actor,
            );
            $migration->paused_nudge_sent_at = Carbon::now();
            $migration->save();
        } catch (\Throwable $e) {
            Log::warning('failed to publish import.migration.paused_nudge', [
                'migration_id' => $migration->id,
                'error' => $e->getMessage(),
            ]);
        }
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
                    'forge' => ForgeImportDriver::for($credential)
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

        try {
            if ($migration->organization) {
                audit_log(
                    $migration->organization,
                    User::find($migration->user_id),
                    'import.migration.expired',
                    $migration,
                    null,
                    ['hours' => (int) $this->option('hours')],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('failed to write expired audit log', [
                'migration_id' => $migration->id,
                'error' => $e->getMessage(),
            ]);
        }

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
