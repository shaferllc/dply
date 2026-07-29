<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token gate for the bundle entitlements API — the machine-to-machine
 * pull the products (tracely/Lookout) use to reconcile entitlement. Dark by
 * default: with no token configured the endpoint 503s so it can't be probed
 * before it's wired. Mirrors {@see ValidateFleetOperatorToken}.
 */
class ValidateBundleServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('bundle.entitlements_api_token');
        if (! is_string($token) || $token === '') {
            abort(503, 'Bundle entitlements API not configured');
        }

        $provided = $request->bearerToken() ?? $request->header('X-Dply-Bundle-Token');
        if (! is_string($provided) || ! hash_equals($token, $provided)) {
            abort(401);
        }

        return $next($request);
    }
}
