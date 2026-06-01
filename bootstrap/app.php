<?php

use App\Console\Commands\CdnSyncMetricsCommand;
use App\Console\Commands\CheckEdgeRumAlertsCommand;
use App\Console\Commands\CheckSupervisorHealthCommand;
use App\Console\Commands\CloudPollStatusCommand;
use App\Console\Commands\DeployIntelligenceScanCommand;
use App\Console\Commands\EvaluateEdgeGuardrailsCommand;
use App\Console\Commands\EvaluateSharedHostBudgetsCommand;
use App\Console\Commands\ExpirePausedImportMigrationsCommand;
use App\Console\Commands\FlushDeployDigestCommand;
use App\Console\Commands\FlushServerSystemdNotificationDigestCommand;
use App\Console\Commands\ProcessInsightDigestQueueCommand;
use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Console\Commands\ProcessSshKeyRotationRemindersCommand;
use App\Console\Commands\PruneAuditLogsCommand;
use App\Console\Commands\PruneFunctionInvocationsCommand;
use App\Console\Commands\PruneServerCreateDraftsCommand;
use App\Console\Commands\PruneServerCronJobRunsCommand;
use App\Console\Commands\PruneTestingHostnameRecordsCommand;
use App\Console\Commands\RevokeExpiredServerSshSessionsCommand;
use App\Console\Commands\ServerlessTickCommand;
use App\Console\Commands\SyncAllOrganizationBillingCommand;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\CaptureReferralCode;
use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureApiTokenAbility;
use App\Http\Middleware\EnsureServerServiceInstalled;
use App\Http\Middleware\EnsureVmPlatformEnabled;
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
use App\Jobs\VerifyEdgeCustomDomainsJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Services\Servers\ServerMetricsGuestScript;
use App\Support\DplyRuntime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
        if (! DplyRuntime::runsScheduler()) {
            return;
        }

        $schedule->call(function (): void {
            Server::query()
                ->where('status', Server::STATUS_READY)
                ->whereNotNull('ip_address')
                ->each(fn (Server $server) => CheckServerHealthJob::dispatch($server));
        })->everyFiveMinutes()->name('dispatch-server-health-checks');

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
        })->everyTenMinutes()->name('dispatch-site-url-health-checks');

        $schedule->call(function (): void {
            if (! config('site_uptime.enabled', true)) {
                return;
            }
            SiteUptimeMonitor::query()
                ->pluck('id')
                ->each(fn (string $id) => RunSiteUptimeMonitorCheckJob::dispatch($id));
        })->everyFiveMinutes()->name('dispatch-site-uptime-checks');

        // SSH login notifications. Dispatches a per-server scan job only for
        // servers that actually have a `server.ssh_login` subscriber — there's
        // no point paying the SSH cost when nothing is listening. The job
        // diffs `last -F` against meta.ssh_login_last_seen_at and publishes
        // a NotificationEvent per new entry.
        $schedule->call(function (): void {
            ScanServerSshLoginsJob::eligibleServers()
                ->each(fn (Server $server) => ScanServerSshLoginsJob::dispatch((string) $server->id));
        })->everyFiveMinutes()->name('dispatch-ssh-login-scans');

        $schedule->command(FlushDeployDigestCommand::class)
            ->hourly()
            ->when(fn (): bool => (int) config('dply.deploy_digest_hours', 0) > 0);

        $schedule->command(EvaluateSharedHostBudgetsCommand::class)
            ->everyFifteenMinutes()
            ->when(fn (): bool => (bool) config('features.workspace.shared_host', true));

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

        $schedule->call(function (): void {
            Artisan::call('dply:edge:collect-usage', [
                '--date' => now()->toDateString(),
            ]);
        })->hourly()->name('edge-usage-today');

        // Roll up managed-serverless invocations into usage snapshots (MTD), so
        // the metered usage line stays current alongside the flat per-function fee.
        $schedule->call(function (): void {
            Artisan::call('dply:serverless:collect-usage', [
                '--date' => now()->toDateString(),
            ]);
        })->hourly()->name('serverless-usage-today');

        $schedule->command(RollupEdgeAnalyticsEngineCommand::class)->hourlyAt(5);

        // Re-evaluate per-site monthly usage guardrails once today's usage
        // collection has landed. Fires the `edge.usage.over_budget`
        // notification on transitions into warn/over.
        $schedule->command(EvaluateEdgeGuardrailsCommand::class)
            ->dailyAt('02:45')
            ->withoutOverlapping();

        $schedule->command(SnapshotOrganizationBillingCommand::class)->dailyAt('02:10');

        $schedule->job(new VerifyEdgeCustomDomainsJob)->everyFifteenMinutes();

        $schedule->command(SyncAllOrganizationBillingCommand::class)->dailyAt('02:30');

        $schedule->command(PruneServerCronJobRunsCommand::class)->dailyAt('03:15');
        $schedule->command(PruneAuditLogsCommand::class)->dailyAt('03:20');
        $schedule->command(CheckEdgeRumAlertsCommand::class)->hourly()->withoutOverlapping();
        $schedule->command(DeployIntelligenceScanCommand::class)->hourly()->withoutOverlapping();
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

        $schedule->command(CdnSyncMetricsCommand::class, ['--all-enabled'])
            ->hourly()
            ->withoutOverlapping();

        $schedule->command(ProcessSshKeyRotationRemindersCommand::class)->dailyAt('08:30');

        $schedule->command(RevokeExpiredServerSshSessionsCommand::class)->everyFiveMinutes();

        $schedule->command(ProcessInsightDigestQueueCommand::class)->dailyAt('08:00');
        $schedule->command(ProcessInsightDigestQueueCommand::class, ['--weekly' => true])->weeklyOn(1, '08:15');

        $schedule->call(function (): void {
            Server::query()
                ->where('status', Server::STATUS_READY)
                ->whereNotNull('ip_address')
                ->pluck('id')
                ->each(fn (string $id) => RunServerInsightsJob::dispatch($id));
        })->hourly()->name('dispatch-server-insights');

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
        })->everyTwoHours()->name('dispatch-site-insights');

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
        })->everyFiveMinutes()->name('dispatch-systemd-inventory-sync');

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
        })->hourly()->name('dispatch-guest-metrics-script-upgrades');

        if (DplyRuntime::isSplitDeployment()) {
            foreach ($schedule->events() as $event) {
                $event->onOneServer();
            }
        }
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
            'vm.platform' => EnsureVmPlatformEnabled::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'hooks/*',
            'webhook/*',
            'webauthn/*',
            'fn/*',
            // Preview-comment widget API is auth'd by per-site widget
            // token in X-Dply-Preview-Widget; called cross-origin from
            // *.dply.host preview hostnames.
            'api/edge/preview-comments/*',
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
            // Workspace deep-link guard: 404s requests for workspace routes the
            // bound server can't reach (tag-gated rows that lack the required
            // installed-service tag; role-gated rows hidden by role_nav_keys).
            // Short-circuits for non-server routes via an `instanceof` check,
            // so the cost is one route-binding lookup per web request.
            EnsureServerServiceInstalled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Friendly handler for cache/queue backend connection failures. With
        // CACHE_STORE=redis (or QUEUE_CONNECTION=redis) pointing at a managed
        // Redis box, an outage means every page render touches a dead Redis
        // connection. config/database.php sets a 2s timeout so this surfaces
        // FAST as a RedisException — without this render handler the operator
        // sees a raw stack trace; with it they get a diagnostic page that
        // names which env vars to inspect.
        $exceptions->render(function (RedisException $e, Request $request) {
            $payload = [
                'error' => 'redis_unreachable',
                'message' => $e->getMessage(),
                'host' => (string) env('REDIS_HOST', '127.0.0.1'),
                'port' => (string) env('REDIS_PORT', '6379'),
                'cacheStore' => (string) env('CACHE_STORE', 'database'),
                'queueConnection' => (string) env('QUEUE_CONNECTION', 'sync'),
                'timeout' => (string) env('REDIS_TIMEOUT', '2.0'),
            ];

            // True API callers (Accept: application/json, no X-Livewire) get
            // the raw payload so they can act programmatically. Everything
            // else — GET pages, plain POSTs, AND Livewire updates — gets the
            // rendered HTML diagnostic. Livewire's POST returning HTML 503
            // surfaces in the browser as a navigation to the response body,
            // which is what we want here: a self-contained error page the
            // operator can read regardless of how the request originated.
            $isApiClient = $request->expectsJson() && ! $request->hasHeader('X-Livewire');

            if ($isApiClient) {
                return response()->json($payload, 503);
            }

            return response()->view('errors.redis-unreachable', $payload, 503);
        });
    })->create();
