<?php

declare(strict_types=1);

namespace Dply\Core\Security;

/**
 * HMAC-SHA256 helpers for X-Dply-Signature (legacy body-only and timestamped variants).
 *
 * Apps own HTTP, caching, and skew checks; this class is pure crypto + format.
 */
final class WebhookSignature
{
    public static function headerWithHmacSha256(string $hmacHex): string
    {
        return 'sha256='.$hmacHex;
    }

    public static function hmacSha256Hex(string $secret, string $message): string
    {
        return hash_hmac('sha256', $message, $secret);
    }

    /** Legacy: signature over raw body only. */
    public static function expectedLegacyHeader(string $secret, string $rawBody): string
    {
        return self::headerWithHmacSha256(self::hmacSha256Hex($secret, $rawBody));
    }

    /** Preferred: signature over "{timestamp}.{rawBody}". */
    public static function expectedTimestampedHeader(string $secret, int $unixTimestamp, string $rawBody): string
    {
        $signedPayload = $unixTimestamp.'.'.$rawBody;

        return self::headerWithHmacSha256(self::hmacSha256Hex($secret, $signedPayload));
    }

    public static function matchesExpectedHeader(string $expectedHeader, string $xDplySignatureHeader): bool
    {
        if ($xDplySignatureHeader === '') {
            return false;
        }

        return hash_equals($expectedHeader, $xDplySignatureHeader);
    }

    /**
     * Try timestamped signature first (when $unixTimestamp is non-null and > 0), then legacy body-only.
     *
     * @return 'timestamped'|'legacy'|null null means no match (caller handles empty secret, etc.)
     */
    public static function verify(string $secret, string $rawBody, string $xDplySignature, ?int $unixTimestamp): ?string
    {
        if ($xDplySignature === '') {
            return null;
        }

        if ($unixTimestamp !== null && $unixTimestamp > 0) {
            $expected = self::expectedTimestampedHeader($secret, $unixTimestamp, $rawBody);
            if (self::matchesExpectedHeader($expected, $xDplySignature)) {
                return 'timestamped';
            }
        }

        $expectedLegacy = self::expectedLegacyHeader($secret, $rawBody);
        if (self::matchesExpectedHeader($expectedLegacy, $xDplySignature)) {
            return 'legacy';
        }

        return null;
    }
}
