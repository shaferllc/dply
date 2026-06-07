<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
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

        // Gate active in non-local (or forced on with COMING_SOON=true).
        $gateOn = $flag === true || ! $this->isLocalDevelopmentRequest($request);
        if (! $gateOn) {
            return $next($request);
        }

        // Allow-listed IPs see the FULL site; everyone else only sees the
        // coming-soon page (plus the Livewire requests it needs to render and
        // run the waitlist signup form).
        if ($this->ipAllowed($request)) {
            return $next($request);
        }

        if ($request->routeIs('coming-soon') || $request->is('livewire/*')) {
            return $next($request);
        }

        // Machine-to-machine callbacks must never be redirected to the
        // coming-soon teaser. Provisioned servers POST task lifecycle
        // results to /webhook/task/* (update-output, mark-as-failed, …) and
        // deploy/git callbacks land on /hooks/*; the uptime probe hits /up.
        // These have their own auth (signed URLs / webhook secrets / throttle)
        // and carry no session, so a 302 here silently swallows the POST —
        // which is exactly what wedged server provisioning during the beta:
        // every mark-as-failed callback bounced to /coming-soon, so tasks
        // stayed "running" forever and the provision journey spun with no
        // error. Let them through regardless of the gate.
        if ($this->isMachineCallback($request)) {
            return $next($request);
        }

        return redirect()->route('coming-soon');
    }

    /**
     * Unauthenticated machine callbacks that must bypass the coming-soon gate:
     * task lifecycle webhooks, deploy/git hooks, and the health probe. Each has
     * its own request authentication, so skipping the gate is safe.
     */
    private function isMachineCallback(Request $request): bool
    {
        return $request->is('webhook/*', 'hooks/*', 'up')
            || $request->routeIs('webhook.*');
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
     * Is the request from an allow-listed IP? Those clients (and authenticated
     * users) see the full site; everyone else only sees the coming-soon page.
     * Supports IPv4, IPv6, and CIDR ranges via Symfony's IpUtils.
     */
    private function ipAllowed(Request $request): bool
    {
        $allowed = \App\Models\ComingSoonAllowedIp::allowList();
        if ($allowed === []) {
            return false;
        }

        return IpUtils::checkIp((string) $request->ip(), $allowed);
    }
}
