<?php

declare(strict_types=1);

namespace App\Console\Scheduling;

use App\Console\Commands\CdnSyncMetricsCommand;
use App\Console\Commands\CheckEdgeRumAlertsCommand;
use App\Console\Commands\CheckSupervisorHealthCommand;
use App\Console\Commands\CloudPollStatusCommand;
use App\Console\Commands\CollectEdgeUsageCommand;
use App\Console\Commands\CollectRealtimeUsageCommand;
use App\Console\Commands\CollectServerlessUsageCommand;
use App\Console\Commands\DeployIntelligenceScanCommand;
use App\Console\Commands\DispatchGuestMetricsScriptUpgradesCommand;
use App\Console\Commands\DispatchReleaseHygieneScansCommand;
use App\Console\Commands\DispatchSecurityDigestScansCommand;
use App\Console\Commands\DispatchServerHealthChecksCommand;
use App\Console\Commands\DispatchServerInsightsCommand;
use App\Console\Commands\DispatchSiteInsightsCommand;
use App\Console\Commands\DispatchSiteUptimeChecksCommand;
use App\Console\Commands\DispatchSiteUrlHealthChecksCommand;
use App\Console\Commands\DispatchSshLoginScansCommand;
use App\Console\Commands\DispatchSystemdInventorySyncCommand;
use App\Console\Commands\EvaluateEdgeGuardrailsCommand;
use App\Console\Commands\EvaluateSharedHostBudgetsCommand;
use App\Console\Commands\ExpirePausedImportMigrationsCommand;
use App\Console\Commands\FlushDeployDigestCommand;
use App\Console\Commands\FlushServerSystemdNotificationDigestCommand;
use App\Console\Commands\MeterServerLogUsageCommand;
use App\Console\Commands\ProcessInsightDigestQueueCommand;
use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Console\Commands\ProcessScheduledSiteDeletionsCommand;
use App\Console\Commands\ProcessSshKeyRotationRemindersCommand;
use App\Console\Commands\PruneAppLogsCommand;
use App\Console\Commands\PruneAuditLogsCommand;
use App\Console\Commands\PruneBackupDownloadStagingsCommand;
use App\Console\Commands\PruneErrorEventsCommand;
use App\Console\Commands\PruneFeedbackAttachmentsCommand;
use App\Console\Commands\PruneFunctionInvocationsCommand;
use App\Console\Commands\PruneLocalWorkspaceArtifactsCommand;
use App\Console\Commands\PruneNotificationInboxItemsCommand;
use App\Console\Commands\PruneOrphanedSiteDataCommand;
use App\Console\Commands\PruneQuickDownloadsCommand;
use App\Console\Commands\PruneRemoteTaskRunnerCommand;
use App\Console\Commands\PruneServerCreateDraftsCommand;
use App\Console\Commands\PruneServerCronJobRunsCommand;
use App\Console\Commands\PruneSiteUptimeCheckResultsCommand;
use App\Console\Commands\PruneTestingHostnameRecordsCommand;
use App\Console\Commands\RenewServerWildcardCertificatesCommand;
use App\Console\Commands\RevokeExpiredServerSshSessionsCommand;
use App\Console\Commands\RollupEdgeAnalyticsEngineCommand;
use App\Console\Commands\RunDueDeploymentSchedulesCommand;
use App\Console\Commands\RunDueScheduledDeploysCommand;
use App\Console\Commands\SecretsCheckDriftCommand;
use App\Console\Commands\SecretsEscrowCommand;
use App\Console\Commands\SecretsRestoreDrillCommand;
use App\Console\Commands\ServerlessTickCommand;
use App\Console\Commands\SnapshotOrganizationBillingCommand;
use App\Console\Commands\SweepExpiredMaintenanceWindowsCommand;
use App\Console\Commands\SweepSiteHttpErrorsCommand;
use App\Console\Commands\SweepStalledTasksCommand;
use App\Console\Commands\SyncAllOrganizationBillingCommand;
use App\Console\Commands\SyncErrorEventsCommand;
use App\Console\Commands\WarmPoolAutoscaleCommand;
use App\Console\Commands\WorkerPoolAutoscaleCommand;
use App\Console\Commands\WorkerPoolMemberHealthCommand;
use App\Console\Commands\WorkerPoolPrimaryHealthCommand;
use App\Jobs\VerifyEdgeCustomDomainsJob;
use App\Support\DplyRuntime;
use Illuminate\Console\Scheduling\Schedule;

final class DplySchedule
{
    public static function register(Schedule $schedule): void
    {
        $schedule->command(DispatchServerHealthChecksCommand::class)
            ->everyFiveMinutes()
            ->name('dispatch-server-health-checks');

        $schedule->command(DispatchSiteUrlHealthChecksCommand::class)
            ->everyTenMinutes()
            ->name('dispatch-site-url-health-checks');

        $schedule->command(DispatchSiteUptimeChecksCommand::class)
            ->everyFiveMinutes()
            ->name('dispatch-site-uptime-checks');

        // Tier-2 of the server-error-reference feature: sweep PHP-FPM access logs
        // for 5xx responses into the Errors stream. Cadence sits under the
        // sweep_lookback_minutes window so a missed cycle still gets covered.
        $schedule->command(SweepSiteHttpErrorsCommand::class)
            ->everyTenMinutes()
            ->name('sweep-site-http-errors');

        $schedule->command(DispatchSshLoginScansCommand::class)
            ->everyFiveMinutes()
            ->name('dispatch-ssh-login-scans');

        $schedule->command(DispatchSecurityDigestScansCommand::class)
            ->dailyAt('03:15')
            ->withoutOverlapping()
            ->name('dispatch-security-digest-scans');

        $schedule->command(DispatchReleaseHygieneScansCommand::class)
            ->dailyAt('03:25')
            ->withoutOverlapping()
            ->name('dispatch-release-hygiene-scans');

        $schedule->command(FlushDeployDigestCommand::class)
            ->hourly()
            ->when(fn (): bool => (int) config('dply.deploy_digest_hours', 0) > 0);

        $schedule->command(EvaluateSharedHostBudgetsCommand::class)
            ->everyFifteenMinutes()
            ->when(fn (): bool => (bool) config('features.workspace.shared_host', true));

        $schedule->command(ProcessScheduledServerDeletionsCommand::class)->everyMinute();
        $schedule->command(ProcessScheduledSiteDeletionsCommand::class)->everyMinute();

        $schedule->command(CloudPollStatusCommand::class)->everyMinute();

        $schedule->command(RunDueDeploymentSchedulesCommand::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->name('run-due-deployment-schedules');

        // One-off delayed deploys (deploy at a future time, single shot).
        $schedule->command(RunDueScheduledDeploysCommand::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->name('run-due-scheduled-deploys');

        // Worker pools: autoscale by queue backlog, and alert when a pool's
        // primary is unhealthy (manual promote — see WorkerPoolPrimaryHealthCommand).
        $schedule->command(WorkerPoolAutoscaleCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('worker-pools-autoscale');

        $schedule->command(WorkerPoolPrimaryHealthCommand::class)
            ->everyFiveMinutes()
            ->name('worker-pools-primary-health');

        // Catch replicas destroyed out-of-band after the pool settled (the
        // reconciler only checks instance existence while actively converging).
        $schedule->command(WorkerPoolMemberHealthCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('worker-pools-member-health');

        $schedule->command(ServerlessTickCommand::class)
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command(SyncAllOrganizationBillingCommand::class)->dailyAt('02:30');

        $schedule->command(CollectEdgeUsageCommand::class, ['--today' => true])
            ->hourly()
            ->name('edge-usage-today');

        $schedule->command(CollectServerlessUsageCommand::class)
            ->hourly()
            ->name('serverless-usage-today');

        $schedule->command(CollectRealtimeUsageCommand::class)
            ->hourly()
            ->name('realtime-usage-today')
            ->withoutOverlapping();

        // dply Logs ingest metering (read-only; no billing yet). Hourly keeps the
        // current day's GB/day fresh in the UI; the nightly pass finalizes the prior
        // day after late-arriving lines settle, before the 02:10 billing snapshot.
        $schedule->command(MeterServerLogUsageCommand::class)
            ->hourly()
            ->name('server-log-usage-today')
            ->withoutOverlapping();

        $schedule->command(MeterServerLogUsageCommand::class, ['--yesterday' => true])
            ->dailyAt('02:05')
            ->name('server-log-usage-finalize')
            ->withoutOverlapping();

        $schedule->command(RollupEdgeAnalyticsEngineCommand::class)->hourlyAt(5);

        $schedule->command(EvaluateEdgeGuardrailsCommand::class)
            ->dailyAt('02:45')
            ->withoutOverlapping();

        $schedule->command(SnapshotOrganizationBillingCommand::class)->dailyAt('02:10');

        $schedule->job(new VerifyEdgeCustomDomainsJob)->everyFifteenMinutes();

        // Capture failed operations into the dedicated error stream, then cap
        // its growth nightly. The sweeper polls the source tables (failures are
        // written via the query builder, which bypasses model events).
        $schedule->command(SyncErrorEventsCommand::class)->everyMinute()->withoutOverlapping();
        $schedule->command(PruneErrorEventsCommand::class)->dailyAt('03:25');
        $schedule->command(PruneNotificationInboxItemsCommand::class)->dailyAt('03:35');

        // Backstop for remote tasks that go silent (rejected webhook, OOM/reboot,
        // dropped network): fail any `running` task past its timeout or with no
        // heartbeat so wedged provisions surface + recover instead of spinning
        // forever. Eloquent updates here trip TaskRunnerTaskObserver, which
        // flips the server to setup_status=FAILED.
        $schedule->command(SweepStalledTasksCommand::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->name('sweep-stalled-tasks');

        // Auto-clear timed visitor maintenance windows once their `until`
        // passes — otherwise sites stay suspended until someone opens the
        // Maintenance page (which is the only other caller of refreshExpired).
        $schedule->command(SweepExpiredMaintenanceWindowsCommand::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->name('sweep-expired-maintenance-windows');

        // Keep warm-pool buckets topped up to their min + retire idle surplus.
        // No-op unless warm_pool.enabled and buckets are configured.
        $schedule->command(WarmPoolAutoscaleCommand::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->when(fn (): bool => (bool) config('warm_pool.enabled', false))
            ->name('warm-pool-autoscale');

        $schedule->command(PruneServerCronJobRunsCommand::class)->dailyAt('03:15');
        $schedule->command(PruneAuditLogsCommand::class)->dailyAt('03:20');
        $schedule->command(CheckEdgeRumAlertsCommand::class)->hourly()->withoutOverlapping();
        $schedule->command(DeployIntelligenceScanCommand::class)->hourly()->withoutOverlapping();
        $schedule->command(PruneTestingHostnameRecordsCommand::class)->dailyAt('03:30');
        $schedule->command(RenewServerWildcardCertificatesCommand::class)
            ->dailyAt('03:35')
            ->withoutOverlapping()
            ->name('renew-server-wildcard-certs');
        $schedule->command(PruneServerCreateDraftsCommand::class)->dailyAt('03:45');
        // 4h-TTL download stagings need finer-than-daily pruning (S3 lifecycle min
        // is 1 day), so sweep every 15 minutes. onOneServer is auto-applied below.
        $schedule->command(PruneBackupDownloadStagingsCommand::class)
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->name('prune-backup-download-stagings');
        $schedule->command(PruneQuickDownloadsCommand::class)
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->name('prune-quick-downloads');
        $schedule->command(PruneFunctionInvocationsCommand::class)->dailyAt('03:50');
        $schedule->command(PruneFeedbackAttachmentsCommand::class)->dailyAt('04:25');

        // Safety net for orphaned site relations (errors/logs/polymorphic links)
        // left by any delete that bypassed Site::deleting + SiteRelationPurger.
        $schedule->command(PruneOrphanedSiteDataCommand::class)->weeklyOn(1, '04:40');
        $schedule->command(PruneSiteUptimeCheckResultsCommand::class)->dailyAt('03:55');
        $schedule->command(PruneAppLogsCommand::class)->dailyAt('04:05');
        $schedule->command(ExpirePausedImportMigrationsCommand::class)->hourly();

        // Local control-plane build scratch (serverless artifacts / repo caches /
        // task-runner temp). Local filesystem work, so it runs in the background
        // rather than blocking the scheduler tick. Per-box files — see the
        // onOneServer caveat in the class docblock for split deployments.
        $schedule->command(PruneLocalWorkspaceArtifactsCommand::class)
            ->dailyAt('04:10')
            ->runInBackground()
            ->when(fn (): bool => (bool) config('dply.local_workspace_prune.enabled', true))
            ->name('prune-local-workspaces');

        // Remote counterpart: SSH each ready box and age-prune ~/.dply-task-runner,
        // which the task runner fills with per-task <id>.sh/.log and never cleans.
        // Per-server SSH, so run it in the background off the scheduler tick.
        $schedule->command(PruneRemoteTaskRunnerCommand::class)
            ->dailyAt('04:15')
            ->runInBackground()
            ->withoutOverlapping()
            ->when(fn (): bool => (bool) config('dply.remote_task_runner_prune.enabled', true))
            ->name('prune-remote-task-runner');

        $schedule->command(CheckSupervisorHealthCommand::class)
            ->everyFifteenMinutes()
            ->when(fn (): bool => (bool) config('dply.supervisor_health_check_enabled', true));

        $schedule->command(CdnSyncMetricsCommand::class, ['--all-enabled'])
            ->hourly()
            ->withoutOverlapping();

        $schedule->command(ProcessSshKeyRotationRemindersCommand::class)->dailyAt('08:30');

        $schedule->command(RevokeExpiredServerSshSessionsCommand::class)->everyFiveMinutes();

        $schedule->command(ProcessInsightDigestQueueCommand::class)->dailyAt('08:00');
        $schedule->command(ProcessInsightDigestQueueCommand::class, ['--weekly' => true])->weeklyOn(1, '08:15');

        $schedule->command(DispatchServerInsightsCommand::class)
            ->hourly()
            ->name('dispatch-server-insights');

        $schedule->command(DispatchSiteInsightsCommand::class)
            ->everyTwoHours()
            ->name('dispatch-site-insights');

        $schedule->command(DispatchSystemdInventorySyncCommand::class)
            ->everyFiveMinutes()
            ->name('dispatch-systemd-inventory-sync');

        $schedule->command(FlushServerSystemdNotificationDigestCommand::class)
            ->hourlyAt(12)
            ->when(fn (): bool => (bool) config('server_services.systemd_digest_flush_enabled', true));

        $schedule->command(DispatchGuestMetricsScriptUpgradesCommand::class)
            ->hourly()
            ->name('dispatch-guest-metrics-script-upgrades');

        // Secret-vault (app-native, W1 off-box break-glass): daily age-encrypted
        // escrow of the platform .env (→ APP_KEY), an independent DB dump, and the
        // fast-recovery critical-keys bundle. dply's own ServerBackupSchedule is
        // the PRIMARY DB backup; this dump is the provider-independent copy.
        $schedule->command(SecretsEscrowCommand::class, ['--source' => 'platform-env'])
            ->dailyAt('04:20')
            ->withoutOverlapping()
            ->name('secrets-escrow-env');
        $schedule->command(SecretsEscrowCommand::class, ['--source' => 'db-dump'])
            ->dailyAt('04:30')
            ->withoutOverlapping()
            ->name('secrets-escrow-db-dump');
        $schedule->command(SecretsEscrowCommand::class, ['--source' => 'critical-keys'])
            ->dailyAt('04:35')
            ->withoutOverlapping()
            ->name('secrets-escrow-critical-keys')
            ->when(fn (): bool => filled(config('secret_vault.critical_keys.pg_password')) || filled(config('secret_vault.critical_keys.ssh_recovery_key_path')));

        // Restore drill runs ONLY on the isolated drill host (it alone holds the
        // age identity + a scratch DB), gated by SECRET_VAULT_DRILL_ENABLED.
        $schedule->command(SecretsRestoreDrillCommand::class)
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->name('secrets-restore-drill')
            ->when(fn (): bool => (bool) config('secret_vault.drill.enabled'));

        // APP_KEY drift across the control-plane boxes (W5) — alerts on divergence.
        $schedule->command(SecretsCheckDriftCommand::class)
            ->hourly()
            ->name('secrets-check-drift')
            ->when(fn (): bool => filled(config('secret_vault.drift.targets')));

        if (DplyRuntime::isSplitDeployment()) {
            foreach ($schedule->events() as $event) {
                $event->onOneServer();
            }
        }
    }
}
