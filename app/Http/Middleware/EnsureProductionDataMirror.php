<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ProductionData\ProductionDataMirror;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard gate for `/live/*` — 404 unless APP_ENV=local (and config enabled).
 */
class EnsureProductionDataMirror
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ProductionDataMirror::available()) {
            abort(404);
        }

        return $next($request);
    }
}
