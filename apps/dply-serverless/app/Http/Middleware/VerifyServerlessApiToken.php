<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyServerlessApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('serverless.api_token');
        if ($token === null || $token === '') {
            abort(503, 'API deploy is not configured.');
        }

        $bearer = $request->bearerToken();
        if ($bearer === null || ! hash_equals($token, $bearer)) {
            abort(401, 'Unauthorized.');
        }

        return $next($request);
    }
}
