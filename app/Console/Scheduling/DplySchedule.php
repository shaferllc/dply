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
use App\Console\Commands\ProcessInsightDigestQueueCommand;
use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Console\Commands\ProcessScheduledSiteDeletionsCommand;
use App\Console\Commands\ProcessSshKeyRotationRemindersCommand;
use App\Console\Commands\PruneAuditLogsCommand;
use App\Console\Commands\PruneFunctionInvocationsCommand;
use App\Console\Commands\PruneLocalWorkspaceArtifactsCommand;
use App\Console\Commands\PruneServerCreateDraftsCommand;
use App\Console\Commands\PruneServerCronJobRunsCommand;
use App\Console\Commands\PruneTestingHostnameRecordsCommand;
use App\Console\Commands\RevokeExpiredServerSshSessionsCommand;
use App\Console\Commands\RollupEdgeAnalyticsEngineCommand;
use App\Console\Commands\RunDueDeploymentSchedulesCommand;
use App\Console\Commands\ServerlessTickCommand;
use App\Console\Commands\SnapshotOrganizationBillingCommand;
use App\Console\Commands\SyncAllOrganizationBillingCommand;
use App\Console\Commands\WorkerPoolAutoscaleCommand;
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

        $schedule->command(DispatchSshLoginScansCommand::class)
            ->everyFiveMinutes()
            ->name('dispatch-ssh-login-scans');

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

        // Worker pools: autoscale by queue backlog, and alert when a pool's
        // primary is unhealthy (manual promote — see WorkerPoolPrimaryHealthCommand).
        $schedule->command(WorkerPoolAutoscaleCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('worker-pools-autoscale');

        $schedule->command(WorkerPoolPrimaryHealthCommand::class)
            ->everyFiveMinutes()
            ->name('worker-pools-primary-health');

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

        $schedule->command(RollupEdgeAnalyticsEngineCommand::class)->hourlyAt(5);

        $schedule->command(EvaluateEdgeGuardrailsCommand::class)
            ->dailyAt('02:45')
            ->withoutOverlapping();

        $schedule->command(SnapshotOrganizationBillingCommand::class)->dailyAt('02:10');

        $schedule->job(new VerifyEdgeCustomDomainsJob)->everyFifteenMinutes();

        $schedule->command(PruneServerCronJobRunsCommand::class)->dailyAt('03:15');
        $schedule->command(PruneAuditLogsCommand::class)->dailyAt('03:20');
        $schedule->command(CheckEdgeRumAlertsCommand::class)->hourly()->withoutOverlapping();
        $schedule->command(DeployIntelligenceScanCommand::class)->hourly()->withoutOverlapping();
        $schedule->command(PruneTestingHostnameRecordsCommand::class)->dailyAt('03:30');
        $schedule->command(PruneServerCreateDraftsCommand::class)->dailyAt('03:45');
        $schedule->command(PruneFunctionInvocationsCommand::class)->dailyAt('03:50');
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

        if (DplyRuntime::isSplitDeployment()) {
            foreach ($schedule->events() as $event) {
                $event->onOneServer();
            }
        }
    }
}
