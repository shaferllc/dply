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
        if (auth()->check()) {
            return $next($request);
        }

        if ($this->isLocalDevelopmentRequest($request)) {
            return $next($request);
        }

        if ($request->is('livewire/*')) {
            return $next($request);
        }

        if ($this->routeIsAllowed($request)) {
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
