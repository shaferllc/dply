<?php

use App\Jobs\CheckServerHealthJob;
use App\Models\Server;
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
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'org' => \App\Http\Middleware\SetCurrentOrganization::class,
            'auth.api' => \App\Http\Middleware\AuthenticateApiToken::class,
            'ability' => \App\Http\Middleware\EnsureApiTokenAbility::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'hooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
