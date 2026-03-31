<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for POST /api/metrics — uses the same secret as outbound {@see ServerMetricsIngestClient}.
 */
class ValidateMetricsIngestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('server_metrics.ingest.token');
        if (! is_string($token) || $token === '') {
            abort(503, 'Metrics ingest is not configured (set DPLY_METRICS_INGEST_TOKEN).');
        }

        $bearer = $request->bearerToken() ?? '';
        if ($bearer === '' || ! hash_equals($token, $bearer)) {
            abort(401, 'Invalid metrics ingest token.');
        }

        return $next($request);
    }
}
