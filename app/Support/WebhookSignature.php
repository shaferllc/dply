<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HMAC-SHA256 signature helpers shared by inbound + outbound webhooks.
 *
 * Two formats:
 *
 *   timestamped — Stripe-style. `t=<unix>,v1=<hex>` where
 *                 hex = hmac_sha256("<unix>.<body>", $secret).
 *                 Verifying requires the X-Dply-Timestamp header.
 *
 *   legacy      — Plain HMAC of the body. `sha256=<hex>` where
 *                 hex = hmac_sha256($body, $secret). No timestamp.
 *
 * The original `Dply\Core\Security\WebhookSignature` was referenced from
 * production code and tests but the class was never extracted into a
 * package — calls 500'd whenever the signature path was hit. This is the
 * App-namespaced replacement.
 */
class WebhookSignature
{
    /**
     * Timestamped header value: `t=<unix>,v1=<hex>`.
     */
    public static function expectedTimestampedHeader(string $secret, int $timestamp, string $body): string
    {
        $hex = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return 't='.$timestamp.',v1='.$hex;
    }

    /**
     * Legacy header value: `sha256=<hex>` over the body alone.
     */
    public static function expectedLegacyHeader(string $secret, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /**
     * Verify a header value. Returns 'timestamped' / 'legacy' / 'invalid'.
     *
     * The caller is expected to validate the timestamp's freshness
     * separately (skew tolerance is policy, not crypto).
     */
    public static function verify(string $secret, string $payload, string $sigHeader, ?int $timestamp = null): string
    {
        $sigHeader = trim($sigHeader);
        if ($sigHeader === '') {
            return 'invalid';
        }

        if ($timestamp !== null && self::looksTimestamped($sigHeader)) {
            $expected = self::expectedTimestampedHeader($secret, $timestamp, $payload);
            if (hash_equals($expected, $sigHeader)) {
                return 'timestamped';
            }

            return 'invalid';
        }

        $legacyExpected = self::expectedLegacyHeader($secret, $payload);
        if (hash_equals($legacyExpected, $sigHeader)) {
            return 'legacy';
        }

        return 'invalid';
    }

    private static function looksTimestamped(string $sigHeader): bool
    {
        return str_starts_with($sigHeader, 't=') && str_contains($sigHeader, ',v1=');
    }
}
