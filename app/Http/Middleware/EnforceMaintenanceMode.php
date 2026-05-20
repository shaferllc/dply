<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the global.maintenance_mode flag is ON, return a 503 maintenance page
 * to all web requests except an allowlist (health, login, admin, logout)
 * so staff can still authenticate and flip the flag off.
 *
 * This is distinct from Laravel's php artisan down — that requires shell
 * access and writes to filesystem; this lets the on-call rotation toggle
 * via `php artisan feature:set global.maintenance_mode --on --reason=...`
 * or, eventually, an admin UI.
 */
class EnforceMaintenanceMode
{
    /** @var list<string> Route names that bypass maintenance mode. */
    private const ALLOW_ROUTES = [
        'login',
        'logout',
        'admin.dashboard',
        'two-factor.login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Feature::active('global.maintenance_mode')) {
            return $next($request);
        }

        $routeName = (string) ($request->route()?->getName() ?? '');
        if (in_array($routeName, self::ALLOW_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'dply is down for maintenance. Try again shortly.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->view('errors.503', status: Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
