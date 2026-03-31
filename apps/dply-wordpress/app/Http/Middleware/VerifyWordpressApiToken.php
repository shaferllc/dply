<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWordpressApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('wordpress.api_token');
        if ($token === null || $token === '') {
            abort(503, 'WordPress control plane API is not configured.');
        }

        $bearer = $request->bearerToken();
        if ($bearer === null || ! hash_equals((string) $token, $bearer)) {
            abort(401, 'Unauthorized.');
        }

        return $next($request);
    }
}
