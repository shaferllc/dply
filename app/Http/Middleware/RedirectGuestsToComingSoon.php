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

        // Fully disabled, or an authenticated user — always pass.
        if ($flag === false || auth()->check()) {
            return $next($request);
        }

        // Coming-soon gates ONLY the registration flow — the marketing site
        // (homepage, features, roadmap, login, …) stays public. A guest who
        // tries to sign up is sent to the coming-soon / waitlist page.
        // Local dev is exempt unless COMING_SOON=true forces it on (for preview).
        $gateOn = $flag === true || ! $this->isLocalDevelopmentRequest($request);

        if ($gateOn && $request->routeIs($this->gatedRoutes())) {
            return redirect()->route('coming-soon');
        }

        return $next($request);
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

    /**
     * The only routes gated behind coming-soon: the registration flow. The rest
     * of the site stays public (homepage, features, login, etc.).
     *
     * @return list<string>
     */
    private function gatedRoutes(): array
    {
        return [
            'register',
            'register.*',
        ];
    }
}
