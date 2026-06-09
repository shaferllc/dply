<?php

use App\Console\Scheduling\DplySchedule;
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
use App\Support\DplyRuntime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        DplySchedule::register($schedule);
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
        // Machine/external callback paths come from the single canonical list
        // (App\Support\MachineCallbackPaths) the guest gates also use, so a new
        // webhook can't be CSRF-exempt-but-gate-blocked (or vice-versa). The `up`
        // health route in that list is a harmless extra here (GETs aren't CSRF
        // checked); webauthn is CSRF-specific so it's appended separately.
        $middleware->preventRequestForgery(except: array_merge(
            \App\Support\MachineCallbackPaths::PATTERNS,
            [
                // Passkey ceremony endpoints (cross-origin, token-auth'd).
                'webauthn/*',
            ],
        ));

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

        // A 404 raised inside a Livewire request must NOT render the full-page
        // errors.layout: that view carries <x-site-header />, so when Livewire
        // morphs (wire:navigate) or injects (update overlay) it into a page that
        // already has the header, you get a duplicated header and a broken-looking
        // nested 404. Serve a chrome-less variant to Livewire requests instead —
        // it morphs in cleanly and offers a Refresh, since the usual cause is a
        // stale snapshot pointing at a route/resource that has since moved.
        // (API callers still fall through to Laravel's JSON 404.)
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            // X-Livewire = component update (POST); X-Livewire-Navigate = the
            // wire:navigate SPA fetch (GET) — the latter is what morphed the
            // duplicated header in. Catch both.
            if ($request->hasHeader('X-Livewire') || $request->hasHeader('X-Livewire-Navigate')) {
                return response()->view('errors.livewire-404', [], 404);
            }
        });
    })->create();
