<?php

use App\Http\Middleware\EnsureEdgeInternalSpikeEnabled;
use App\Http\Middleware\VerifyEdgeApiToken;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'edge.internal' => EnsureEdgeInternalSpikeEnabled::class,
            'edge.token' => VerifyEdgeApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Plain response avoids a second render pass (full error page + cookies) that can
        // follow MissingAppKeyException and trigger "headers already sent" fatals in logs.
        $exceptions->render(function (MissingAppKeyException $e, Request $request) {
            $message = "Application encryption key is missing. Set APP_KEY in apps/dply-edge/.env\n\n".
                "Run: cd apps/dply-edge && php artisan key:generate --force\n".
                "Or copy the APP_KEY line from .env.example.\n";

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Application encryption key is missing. Run php artisan key:generate in apps/dply-edge.',
                ], 500);
            }

            return response($message, 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
        });
    })->create();
