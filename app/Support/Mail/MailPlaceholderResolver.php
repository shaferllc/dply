<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;

/**
 * Resolves `${VAR}` placeholders in operator-entered mail fields (from-name /
 * from-address) against a site's environment — for the things dply does OUTSIDE
 * the deployed app's runtime: registering a Cloudflare sender, the control-plane
 * verification / test send, and the stored MAIL_FROM_NAME.
 *
 * The deployed app's own phpdotenv expands the same `${APP_NAME}` at boot, but
 * dply's provider API calls and previews pass the field verbatim — so without
 * this a literal "${APP_NAME}" ships as the sender's display name (which is
 * exactly what shows up in Cloudflare's senders list).
 *
 * Unknown placeholders are left intact rather than blanked, so a typo stays
 * visible instead of silently vanishing.
 */
class MailPlaceholderResolver
{
    public static function resolve(Site $site, string $value): string
    {
        if ($value === '' || ! str_contains($value, '${')) {
            return $value;
        }

        $env = self::siteEnv($site);

        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            function (array $m) use ($env, $site): string {
                $key = $m[1];
                $resolved = $env[$key] ?? null;

                // APP_NAME is the common case and is often unset in dply's copy of
                // the env (the app derives it) — fall back to the site name, then
                // its primary hostname, before giving up.
                if (($resolved === null || $resolved === '') && $key === 'APP_NAME') {
                    $resolved = trim((string) ($site->name ?? ''))
                        ?: (string) ($site->primaryDomain()?->hostname ?? '');
                }

                return ($resolved !== null && $resolved !== '') ? (string) $resolved : $m[0];
            },
            $value,
        ) ?? $value;
    }

    /** @return array<string, string> */
    private static function siteEnv(Site $site): array
    {
        try {
            $parsed = app(DotEnvFileParser::class)->parse((string) $site->env_file_content);
            $vars = $parsed['variables'] ?? [];

            return is_array($vars) ? $vars : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
