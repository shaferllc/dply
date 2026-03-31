<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateFleetOperatorToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('fleet_operator.token');
        if (! is_string($token) || $token === '') {
            abort(503, 'Operator API not configured');
        }

        $provided = $request->bearerToken() ?? $request->header('X-Fleet-Operator-Token');
        if (! is_string($provided) || ! hash_equals($token, $provided)) {
            abort(401);
        }

        return $next($request);
    }
}
