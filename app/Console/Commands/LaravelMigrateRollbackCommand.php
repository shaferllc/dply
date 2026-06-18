<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSiteForCliCommand;
use App\Models\Snapshot;
use App\Modules\RemoteCli\Services\Artisan;
use App\Modules\RemoteCli\Services\RemoteCliPermissionDeniedException;
use App\Services\Snapshots\SnapshotDestinationFactory;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Console\Command;

/**
 * dply:laravel:migrate:rollback <site> [--step=1] [--snapshot-first|--no-snapshot] [--no-confirm] [--user=]
 *
 * The CI counterpart to the Laravel Migrations sub-tab's "Roll back
 * last batch" button — same SnapshotService → Artisan rollback pair,
 * just scriptable. Defaults to taking a pre-rollback safety snapshot
 * to local disk (Q19) because losing data on a script-driven
 * rollback is the kind of thing operators wake up to at 3am.
 */
class LaravelMigrateRollbackCommand extends Command
{
    use ResolvesSiteForCliCommand;

    protected $signature = 'dply:laravel:migrate:rollback
        {site : Site name or slug}
        {--step=1 : Number of migration batches to roll back}
        {--no-snapshot : Skip the pre-rollback safety snapshot (DANGEROUS — your only undo path)}
        {--no-confirm : Skip the destructive-action prompt (CI-safe)}
        {--user= : User email to act as (audit trail + permission gate)}';

    protected $description = 'Roll back the last N Laravel migration batches with a pre-rollback safety snapshot.';

    public function handle(
        Artisan $artisan,
        SnapshotService $snapshots,
        SnapshotDestinationFactory $destinations,
    ): int {
        $site = $this->resolveSite((string) $this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $step = max(1, (int) $this->option('step'));
        $caller = $this->resolveActingUser($site, $this->option('user'));

        if (! $this->option('no-confirm')) {
            $confirmed = $this->confirm("Roll back {$step} migration batch(es) on {$site->name}?");
            if (! $confirmed) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        // Pre-rollback safety snapshot to local disk per Q19 transient
        // case. --no-snapshot bypasses; we don't loudly warn beyond the
        // option help text because operators who pass it are presumed
        // to have read what it does.
        if (! $this->option('no-snapshot')) {
            try {
                $snapshot = $snapshots->take(
                    site: $site,
                    destination: $destinations->localDisk(),
                    reason: Snapshot::REASON_PRE_MIGRATION_ROLLBACK,
                    userId: $caller?->getKey(),
                );
                $this->info("Pre-rollback snapshot saved: snap-{$snapshot->id}");
            } catch (\Throwable $e) {
                $this->error('Pre-rollback snapshot failed; aborting rollback. '.$e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $result = $artisan->run(
                site: $site,
                command: 'migrate:rollback',
                args: ['--force', '--step='.$step],
                queuedBy: $caller,
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result->stdout() !== '') {
            $this->line($result->stdout());
        }
        if ($result->stderr() !== '') {
            $this->getOutput()->writeln('<fg=red>'.$result->stderr().'</>');
        }

        return $result->exitCode() ?? ($result->isFailed() ? self::FAILURE : self::SUCCESS);
    }
}
