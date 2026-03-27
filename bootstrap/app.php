<?php

use App\Console\Commands\FlushDeployDigestCommand;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureApiTokenAbility;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Middleware\ValidateFleetOperatorToken;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\CheckSiteUrlHealthJob;
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
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'org' => SetCurrentOrganization::class,
            'auth.api' => AuthenticateApiToken::class,
            'ability' => EnsureApiTokenAbility::class,
            'fleet.operator' => ValidateFleetOperatorToken::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'hooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
