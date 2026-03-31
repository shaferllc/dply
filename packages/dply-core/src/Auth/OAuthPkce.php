<?php

declare(strict_types=1);

namespace Dply\Core\Auth;

/**
 * PKCE helpers for OAuth 2.1-style authorization code + public clients (RFC 7636).
 * Use from product apps when starting the authorize redirect to {@see https://auth.dply.io}.
 */
final class OAuthPkce
{
    /**
     * Random URL-safe verifier (43–128 chars per RFC 7636).
     */
    public static function generateCodeVerifier(int $length = 64): string
    {
        $length = max(43, min(128, $length));

        return rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=');
    }

    /**
     * S256 code challenge from verifier.
     */
    public static function codeChallengeS256(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
