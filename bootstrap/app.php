<?php

use App\Console\Commands\CheckSupervisorHealthCommand;
use App\Console\Commands\CloudPollStatusCommand;
use App\Console\Commands\ExpirePausedImportMigrationsCommand;
use App\Console\Commands\FlushDeployDigestCommand;
use App\Console\Commands\FlushServerSystemdNotificationDigestCommand;
use App\Console\Commands\ProcessInsightDigestQueueCommand;
use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Console\Commands\ProcessSshKeyRotationRemindersCommand;
use App\Console\Commands\PruneFunctionInvocationsCommand;
use App\Console\Commands\PruneServerCreateDraftsCommand;
use App\Console\Commands\PruneServerCronJobRunsCommand;
use App\Console\Commands\PruneTestingHostnameRecordsCommand;
use App\Console\Commands\ServerlessTickCommand;
use App\Console\Commands\SyncAllOrganizationBillingCommand;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\CaptureReferralCode;
use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureApiTokenAbility;
use App\Http\Middleware\EnsureServerServiceInstalled;
use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Http\Middleware\ResolveEdgeCustomDomain;
use App\Http\Middleware\ResolveServerlessCustomDomain;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Middleware\ValidateFleetOperatorToken;
use App\Http\Middleware\ValidateMetricsIngestToken;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\CheckSiteUrlHealthJob;
use App\Jobs\RunServerInsightsJob;
use App\Jobs\RunSiteInsightsJob;
use App\Jobs\RunSiteUptimeMonitorCheckJob;
use App\Jobs\ScanServerSshLoginsJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Jobs\UpgradeGuestMetricsScriptJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Services\Servers\ServerMetricsGuestScript;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->call(function (): void {
            Server::query()
                ->where('status', Server::STATUS_READY)
                ->whereNotNull('ip_address')
                ->each(fn (Server $server) => CheckServerHealthJob::dispatch($server));
        })->everyFiveMinutes();

        $schedule->call(function (): void {
            if (! config('dply.site_health_check_enabled', true)) {
                return;
            }
            Site::query()
                ->whereIn('status', [
                    Site::STATUS_NGINX_ACTIVE,
                    Site::STATUS_APACHE_ACTIVE,
                    Site::STATUS_CADDY_ACTIVE,
                    Site::STATUS_OPENLITESPEED_ACTIVE,
                    Site::STATUS_TRAEFIK_ACTIVE,
                ])
                ->whereHas('domains')
                ->pluck('id')
                ->each(fn (int $id) => CheckSiteUrlHealthJob::dispatch($id));
        })->everyTenMinutes();

        $schedule->call(function (): void {
            if (! config('site_uptime.enabled', true)) {
                return;
            }
            SiteUptimeMonitor::query()
                ->pluck('id')
                ->each(fn (string $id) => RunSiteUptimeMonitorCheckJob::dispatch($id));
        })->everyFiveMinutes();

        // SSH login notifications. Dispatches a per-server scan job only for
        // servers that actually have a `server.ssh_login` subscriber — there's
        // no point paying the SSH cost when nothing is listening. The job
        // diffs `last -F` against meta.ssh_login_last_seen_at and publishes
        // a NotificationEvent per new entry.
        $schedule->call(function (): void {
            ScanServerSshLoginsJob::eligibleServers()
                ->each(fn (Server $server) => ScanServerSshLoginsJob::dispatch((string) $server->id));
        })->everyFiveMinutes();

        $schedule->command(FlushDeployDigestCommand::class)
            ->hourly()
            ->when(fn (): bool => (int) config('dply.deploy_digest_hours', 0) > 0);

        $schedule->command(ProcessScheduledServerDeletionsCommand::class)->everyMinute();

        // Sweep edge sites for backend status updates. Runs every
        // minute so an active deploy reaches "active" within ~60s
        // of the backend reporting ready.
        $schedule->command(CloudPollStatusCommand::class)->everyMinute();

        // Drive the Laravel scheduler + queue worker on serverless functions
        // — DigitalOcean Functions has no long-running process of its own.
        $schedule->command(ServerlessTickCommand::class)
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command(SyncAllOrganizationBillingCommand::class)->dailyAt('02:30');

        $schedule->command(PruneServerCronJobRunsCommand::class)->dailyAt('03:15');
        $schedule->command(PruneTestingHostnameRecordsCommand::class)->dailyAt('03:30');
        $schedule->command(PruneServerCreateDraftsCommand::class)->dailyAt('03:45');
        $schedule->command(PruneFunctionInvocationsCommand::class)->dailyAt('03:50');
        // Q17 trust-window enforcement: revoke ephemeral SSH keys for migrations
        // paused beyond 168h. Hourly cadence so the trust window doesn't quietly
        // extend during scheduler downtime.
        $schedule->command(ExpirePausedImportMigrationsCommand::class)->hourly();

        $schedule->command(CheckSupervisorHealthCommand::class)
            ->everyFifteenMinutes()
            ->when(fn (): bool => (bool) config('dply.supervisor_health_check_enabled', true));

        $schedule->command(ProcessSshKeyRotationRemindersCommand::class)->dailyAt('08:30');

        $schedule->command(ProcessInsightDigestQueueCommand::class)->dailyAt('08:00');
        $schedule->command(ProcessInsightDigestQueueCommand::class, ['--weekly' => true])->weeklyOn(1, '08:15');

        $schedule->call(function (): void {
            Server::query()
                ->where('status', Server::STATUS_READY)
                ->whereNotNull('ip_address')
                ->pluck('id')
                ->each(fn (string $id) => RunServerInsightsJob::dispatch($id));
        })->hourly();

        $schedule->call(function (): void {
            Site::query()
                ->whereIn('status', [
                    Site::STATUS_NGINX_ACTIVE,
                    Site::STATUS_APACHE_ACTIVE,
                    Site::STATUS_CADDY_ACTIVE,
                    Site::STATUS_OPENLITESPEED_ACTIVE,
                    Site::STATUS_TRAEFIK_ACTIVE,
                ])
                ->pluck('id')
                ->each(fn (string $id) => RunSiteInsightsJob::dispatch($id));
        })->everyTwoHours();

        $schedule->call(function (): void {
            if (! (bool) config('server_services.systemd_inventory_schedule_enabled', true)) {
                return;
            }
            if (! (bool) config('server_services.systemd_inventory_job_enabled', true)) {
                return;
            }
            Server::query()
                ->where('status', Server::STATUS_READY)
                ->whereNotNull('ip_address')
                ->whereNotNull('ssh_private_key')
                ->pluck('id')
                ->each(fn (string $id) => SyncServerSystemdServicesJob::dispatch($id));
        })->everyFiveMinutes();

        $schedule->command(FlushServerSystemdNotificationDigestCommand::class)
            ->hourlyAt(12)
            ->when(fn (): bool => (bool) config('server_services.systemd_digest_flush_enabled', true));

        // Sweep ready servers and push the bundled metrics script when
        // its SHA differs from what's recorded as deployed. Job is
        // ShouldBeUnique on serverId, so a slow droplet won't pile up
        // duplicate upgrade attempts. The job itself re-checks the
        // bundled SHA at runtime so a queue backlog can't push a stale
        // script. Hourly cadence is the cheapest "new release reaches
        // every droplet within an hour" without making this the
        // queue's primary tenant.
        $schedule->call(function (): void {
            if (! (bool) config('server_metrics.guest_script.scheduled_upgrades_enabled', true)) {
                return;
            }
            $bundledSha = app(ServerMetricsGuestScript::class)->bundledSha256();
            if ($bundledSha === '') {
                return;
            }
            Server::query()
                ->where('status', Server::STATUS_READY)
                ->whereNotNull('ip_address')
                ->whereNotNull('ssh_private_key')
                ->each(function (Server $server) use ($bundledSha): void {
                    $deployedSha = (string) ($server->meta['monitoring_guest_script_sha'] ?? $server->meta['monitoring_guest_script_sha256'] ?? '');
                    if ($deployedSha === $bundledSha) {
                        return;
                    }
                    UpgradeGuestMetricsScriptJob::dispatch($server->id, $bundledSha);
                });
        })->hourly();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trustedProxies !== '') {
            $at = $trustedProxies === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
            $middleware->trustProxies(at: $at);
        }

        $middleware->alias([
            'org' => SetCurrentOrganization::class,
            'auth.api' => AuthenticateApiToken::class,
            'ability' => EnsureApiTokenAbility::class,
            'fleet.operator' => ValidateFleetOperatorToken::class,
            'metrics.ingest' => ValidateMetricsIngestToken::class,
            'server.service.installed' => EnsureServerServiceInstalled::class,
            'feature' => EnsureFeaturesAreActive::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'hooks/*',
            'webhook/*',
            'webauthn/*',
            'fn/*',
        ]);

        // Custom-domain short-circuit MUST run before the normal web stack
        // so a request to `api.acme.com/` doesn't fall through to the
        // marketing welcome view (which has no host constraint on /).
        $middleware->prependToGroup('web', [
            ResolveServerlessCustomDomain::class,
            ResolveEdgeCustomDomain::class,
        ]);

        $middleware->appendToGroup('web', [
            EnforceMaintenanceMode::class,
            CaptureReferralCode::class,
            RedirectGuestsToComingSoon::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
