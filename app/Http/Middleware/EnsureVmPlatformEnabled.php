<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ProductLine\ProductLineKillSwitches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVmPlatformEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ProductLineKillSwitches::blocksVmServerCreate()) {
            abort(503, __('VM provisioning is temporarily disabled by platform administrators.'));
        }

        return $next($request);
    }
}
