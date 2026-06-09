<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Canonical list of non-interactive machine / external-callback request paths.
 *
 * These are hit by provisioned servers, deploy/git providers, billing
 * providers, and customer traffic to deployed functions — NOT by a human
 * browsing with a session. They carry their own authentication (signed URLs,
 * webhook secrets, per-site tokens) and must therefore bypass any guest-facing
 * gate, otherwise the gate silently swallows the request (a 302/503 that the
 * caller's bare `curl` treats as success).
 *
 * This existed only as scattered literals before — the CSRF except-list in
 * bootstrap/app.php, plus an ad-hoc check in RedirectGuestsToComingSoon — and
 * the maintenance-mode gate had none at all. A coming-soon redirect on these
 * paths is exactly what wedged server provisioning (every task callback bounced
 * to /coming-soon). Keep this as the ONE source of truth so a new webhook can't
 * be added behind a gate by accident; consume it from every guest gate.
 */
final class MachineCallbackPaths
{
    /**
     * Path glob patterns (Request::is() syntax) for machine callbacks.
     *
     * @var list<string>
     */
    public const PATTERNS = [
        'webhook/*',                   // TaskRunner lifecycle callbacks (provisioning, runs)
        'hooks/*',                     // deploy / GitHub App / log / vitals webhooks
        'fn/*',                        // public serverless function invocation URLs
        'api/edge/preview-comments/*', // cross-origin preview-comment widget (per-site token)
        'up',                          // uptime/health probe
    ];

    /**
     * Is this request one of the machine callbacks that must skip guest gates?
     */
    public static function matches(Request $request): bool
    {
        return $request->is(...self::PATTERNS);
    }
}
