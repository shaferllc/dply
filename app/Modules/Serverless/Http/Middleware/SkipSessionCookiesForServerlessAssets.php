<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drop control-plane session cookies on hashed Vite /build files (and the
 * same-origin asset paths the Functions proxy serves).
 *
 * Those responses already send `Cache-Control: public`; a Set-Cookie from
 * StartSession makes Cloudflare treat them as private and the next HTML
 * page waits on an uncacheable JS/CSS fetch.
 */
class SkipSessionCookiesForServerlessAssets
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->looksLikeAssetPath($request)) {
            config(['session.driver' => 'array']);
        }

        $response = $next($request);

        if (! $this->shouldStripCookies($request)) {
            return $response;
        }

        $response->headers->remove('Set-Cookie');

        return $response;
    }

    private function shouldStripCookies(Request $request): bool
    {
        return $request->attributes->get('dply.skip_session_cookies') === true
            || $this->looksLikeAssetPath($request);
    }

    private function looksLikeAssetPath(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        if ($path === 'build' || str_starts_with($path, 'build/')) {
            return true;
        }

        if (str_starts_with($path, 'serverless-assets/')) {
            return true;
        }

        return preg_match('#^fn/[^/]+/build(?:/|$)#', $path) === 1;
    }
}
