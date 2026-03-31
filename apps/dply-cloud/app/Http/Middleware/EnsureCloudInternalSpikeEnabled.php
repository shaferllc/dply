<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCloudInternalSpikeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('cloud.internal_spike_enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
