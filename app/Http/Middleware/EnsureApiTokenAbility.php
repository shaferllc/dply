<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token || ! $token->allows($ability)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
