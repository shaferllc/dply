<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectGuestsToComingSoon
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // COMING_SOON env (via config): true forces the gate on (even locally,
        // for preview), false turns it fully off, null = legacy behavior
        // (gated in any non-local environment).
        $flag = config('dply.coming_soon');

        if ($flag === false) {
            return $next($request);
        }

        if (auth()->check()) {
            return $next($request);
        }

        if ($request->is('livewire/*')) {
            return $next($request);
        }

        if ($request->is('hooks/*')) {
            return $next($request);
        }

        // Serverless function URLs are public HTTP endpoints — every caller
        // of a deployed function is a guest by definition.
        if ($request->is('fn/*')) {
            return $next($request);
        }

        if ($request->is('cli/*')) {
            return $next($request);
        }

        if ($this->routeIsAllowed($request)) {
            return $next($request);
        }

        // Local dev passes through UNLESS coming-soon is explicitly forced on
        // (set COMING_SOON=true to preview the gate locally).
        if ($flag !== true && $this->isLocalDevelopmentRequest($request)) {
            return $next($request);
        }

        return redirect()->route('coming-soon');
    }

    private function isLocalDevelopmentRequest(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return in_array($request->getHost(), [
            'localhost',
            '127.0.0.1',
            '::1',
        ], true);
    }

    private function routeIsAllowed(Request $request): bool
    {
        return $request->routeIs([
            'coming-soon',
            'features',
            'changelog',
            'roadmap',
            'login',
            'password.request',
            'password.reset',
            'two-factor.login',
            'oauth.redirect',
            'oauth.callback',
            'oauth.central.redirect',
            'oauth.central.callback',
            'logout',
        ]);
    }
}
