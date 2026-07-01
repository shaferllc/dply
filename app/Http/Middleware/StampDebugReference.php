<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Debug\DebugReference;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps the control-plane error reference (the Lookout occurrence id) onto 5xx
 * responses as `X-Dply-Ref`, so it's trivially copyable from the network tab /
 * curl and matches the ref shown on the branded 500 page and stored in Lookout.
 *
 * Runs outbound after the exception handler has reported the error, so the
 * occurrence id is populated by the time we read it. No-op when there's no
 * reference (SDK absent or nothing reported for this request).
 */
class StampDebugReference
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() >= 500 && ! $response->headers->has('X-Dply-Ref')) {
            $ref = DebugReference::current();
            if ($ref !== null) {
                $response->headers->set('X-Dply-Ref', $ref);
            }
        }

        return $response;
    }
}
