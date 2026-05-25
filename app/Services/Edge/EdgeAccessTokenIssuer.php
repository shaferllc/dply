<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeSiteAccessRule;
use App\Models\Site;
use App\Models\User;

/**
 * HS256 JWTs consumed by the Edge Worker preview access gate.
 */
final class EdgeAccessTokenIssuer
{
    /**
     * @return array{token: string, expires_at: int}
     */
    public function issue(Site $site, string $hostname, User $user, EdgeSiteAccessRule $rule): array
    {
        $expiresAt = now()->addHours(24)->getTimestamp();
        $payload = [
            'site_id' => (string) $site->id,
            'hostname' => strtolower(trim($hostname)),
            'email' => strtolower(trim((string) $user->email)),
            'exp' => $expiresAt,
        ];

        return [
            'token' => $this->encode($payload, $rule->cookie_secret),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload, string $secret): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, $secret, true));

        return $header.'.'.$body.'.'.$signature;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
