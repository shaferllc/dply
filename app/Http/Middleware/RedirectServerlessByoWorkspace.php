<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Site;
use App\Support\Serverless\ServerlessWorkspaceUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send function Sites off the BYO `/servers/{server}/sites/{site}/…` shape onto
 * `/serverless/{site}/…` so the address bar matches the Serverless product line.
 */
final class RedirectServerlessByoWorkspace
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        if (! $site instanceof Site) {
            return $next($request);
        }

        $target = ServerlessWorkspaceUrl::legacyRedirectUrl($request, $site);
        if ($target === null) {
            return $next($request);
        }

        // Avoid a no-op redirect loop when the generated URL matches the request.
        $current = $request->fullUrl();
        if ($target === $current || rtrim($target, '/') === rtrim($current, '/')) {
            return $next($request);
        }

        return redirect()->to($target);
    }
}
