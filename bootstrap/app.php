<?php

use App\Console\Commands\CheckSupervisorHealthCommand;
use App\Console\Commands\FlushDeployDigestCommand;
use App\Console\Commands\FlushServerSystemdNotificationDigestCommand;
use App\Console\Commands\ProcessInsightDigestQueueCommand;
use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Console\Commands\ProcessSshKeyRotationRemindersCommand;
use App\Console\Commands\PruneServerCronJobRunsCommand;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\CaptureReferralCode;
use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Http\Middleware\EnsureApiTokenAbility;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Middleware\ValidateFleetOperatorToken;
use App\Http\Middleware\ValidateMetricsIngestToken;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\CheckSiteUrlHealthJob;
use App\Jobs\RunServerInsightsJob;
use App\Jobs\RunSiteInsightsJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
                ->where('status', Site::STATUS_NGINX_ACTIVE)
                ->whereHas('domains')
                ->pluck('id')
                ->each(fn (int $id) => CheckSiteUrlHealthJob::dispatch($id));
        })->everyTenMinutes();

        $schedule->command(FlushDeployDigestCommand::class)
            ->hourly()
            ->when(fn (): bool => (int) config('dply.deploy_digest_hours', 0) > 0);

        $schedule->command(ProcessScheduledServerDeletionsCommand::class)->everyMinute();

        $schedule->command(PruneServerCronJobRunsCommand::class)->dailyAt('03:15');

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
                ->where('status', Site::STATUS_NGINX_ACTIVE)
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
        ]);
        $middleware->validateCsrfTokens(except: [
            'hooks/*',
            'webhook/*',
        ]);

        $middleware->appendToGroup('web', [
            CaptureReferralCode::class,
            RedirectGuestsToComingSoon::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
