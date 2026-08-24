<?php

declare(strict_types=1);

namespace App\Support\Acme;

/**
 * Where certbot's DNS hook calls back to, and what it signs with.
 *
 * Kernel rather than controller statics, because both sides of this contract
 * need them: {@see \App\Http\Controllers\AcmeDnsHookController} verifies the
 * signature on the way in, and
 * {@see \App\Modules\Certificates\Services\WildcardCertificateIssuer} writes
 * both values into the credentials file it ships to the server.
 *
 * The module used to read them off the controller directly, which is a
 * module→shell dependency — the one edge `tests/Unit/ModuleBoundaryTest`
 * rejects, and the reason it prints "move the shared piece into the kernel and
 * depend on that from both sides". Nothing here is HTTP: both values are
 * derived purely from config, so the controller was never their natural home.
 */
final class AcmeDnsHook
{
    /**
     * The HMAC key the hook signs with.
     *
     * Falls back to the app key so a fresh install has a working hook without
     * configuring anything — an unsigned hook would be an open DNS-write
     * endpoint, so there is no safe "unset" state to fall back to instead.
     */
    public static function secret(): string
    {
        $explicit = trim((string) config('services.cloudflare.acme_hook_secret', ''));

        if ($explicit !== '') {
            return $explicit;
        }

        return (string) config('app.key', '');
    }

    /**
     * The publicly reachable callback URL.
     *
     * Prefers `dply.public_app_url` over `app.url` for the same reason the
     * queue and cache endpoints do: certbot runs on a customer's server and
     * resolves this from the open internet, where a local *.test APP_URL is
     * unreachable.
     */
    public static function url(): string
    {
        $base = rtrim((string) (config('dply.public_app_url') ?: config('app.url')), '/');

        return $base.'/hooks/acme-dns';
    }
}
