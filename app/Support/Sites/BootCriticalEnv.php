<?php

declare(strict_types=1);

namespace App\Support\Sites;

/**
 * The env keys whose absence genuinely breaks a first boot — the ONLY keys the
 * first-deploy setup wizard blocks the deploy on.
 *
 * The env-requirement scanner marks a key "required" the moment it appears in
 * .env.example or as a no-default env() call. For a real app that's hundreds of
 * keys (dply itself scans ~172 "required") — almost all of them optional
 * integrations (ABLY_KEY, every DEBUGBAR_*, CLOUDFLARE_*, …). Holding the first
 * deploy on all of those makes onboarding unusable.
 *
 * So the wizard separates "detected" (show them all, pre-filled, editable) from
 * "blocking" (hold the deploy). Blocking = required + no usable example + in
 * this curated set: the framework URL and the database connection (which the
 * Resources step provisions and injects, so filling them is a guided action,
 * not a wall of text inputs). Everything else is surfaced as optional/advanced.
 */
final class BootCriticalEnv
{
    /** Standalone boot-critical keys (and the URL-style DB shortcuts). */
    private const EXACT = ['APP_URL', 'DATABASE_URL', 'DB_URL'];

    /** Canonical database connection keys — satisfied by the Resources step. */
    private const DB_CONNECTION = [
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    ];

    public static function isBootCritical(string $key): bool
    {
        $k = strtoupper(trim($key));

        return in_array($k, self::EXACT, true) || in_array($k, self::DB_CONNECTION, true);
    }
}
