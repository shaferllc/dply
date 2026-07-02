<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services;

use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Validates a stored Git provider token against its provider and stamps the
 * result (last_validated_at / expires_at / validation_error) on the row.
 *
 * The expiry is the provider's REAL one where available — GitHub returns it in
 * the `github-authentication-token-expiration` response header (fine-grained
 * PATs expire after 30 days by default, which is exactly how deploys start
 * failing "out of nowhere"); GitLab exposes it on /personal_access_tokens/self.
 *
 * Network failures are deliberately NOT recorded as validation errors — an
 * unreachable API says nothing about the token. Only a definitive provider
 * rejection (401/403) marks the token unhealthy.
 */
class GitProviderTokenHealth
{
    /**
     * Validate and stamp. Returns true when the token is healthy, false when
     * the provider rejected it, null when validation couldn't run (network).
     */
    public function refresh(GitProviderToken|SocialAccount $token): ?bool
    {
        $accessToken = $token->accessToken();
        if ($accessToken === '') {
            $token->update([
                'validation_error' => 'The stored token could not be decrypted (APP_KEY drift) — replace it.',
            ]);

            return false;
        }

        $base = $token->apiBaseUrl();
        if ($base === '') {
            return null;
        }

        try {
            return match ($token->provider()) {
                'github' => $this->refreshGithub($token, $base, $accessToken),
                'gitlab' => $this->refreshGitlab($token, $base, $accessToken),
                'bitbucket' => $this->refreshSimple($token, $base.'/2.0/user', $accessToken),
                default => null,
            };
        } catch (\Throwable) {
            // Connection-level failure — not the token's fault; leave the row as-is.
            return null;
        }
    }

    private function refreshGithub(GitProviderToken|SocialAccount $token, string $base, string $accessToken): bool
    {
        $request = Http::withToken($accessToken)->acceptJson()->withHeaders([
            'User-Agent' => 'Dply (token-health)',
            'Accept' => 'application/vnd.github+json',
        ]);

        $response = $request->get($base.'/user');

        // Fine-grained PATs without profile-read return 403/404 on /user even
        // when perfectly valid — same fallback as the connect-time validation.
        if (! $response->successful() && in_array($response->status(), [403, 404], true)) {
            $response = $request->get($base.'/user/repos', ['per_page' => 1]);
        }

        if ($response->successful()) {
            $this->stampHealthy($token, $this->githubExpiry($response));

            return true;
        }

        $this->stampRejected($token, $response);

        return false;
    }

    private function refreshGitlab(GitProviderToken|SocialAccount $token, string $base, string $accessToken): bool
    {
        $response = Http::withToken($accessToken)->acceptJson()
            ->get($base.'/api/v4/personal_access_tokens/self');

        if ($response->successful()) {
            $expiresAt = null;
            $raw = (string) ($response->json('expires_at') ?? '');
            if ($raw !== '') {
                try {
                    $expiresAt = Carbon::parse($raw);
                } catch (\Throwable) {
                    $expiresAt = null;
                }
            }
            $this->stampHealthy($token, $expiresAt);

            return true;
        }

        // OAuth-ish tokens 404 on that endpoint — fall back to a plain user check.
        if ($response->status() === 404) {
            return $this->refreshSimple($token, $base.'/api/v4/user', $accessToken);
        }

        $this->stampRejected($token, $response);

        return false;
    }

    private function refreshSimple(GitProviderToken|SocialAccount $token, string $url, string $accessToken): bool
    {
        $response = Http::withToken($accessToken)->acceptJson()->get($url);

        if ($response->successful()) {
            $this->stampHealthy($token, null);

            return true;
        }

        $this->stampRejected($token, $response);

        return false;
    }

    /** GitHub sends the token's real expiry as a response header, e.g. "2026-07-02 03:41:41 UTC". */
    private function githubExpiry(Response $response): ?Carbon
    {
        $raw = trim((string) $response->header('github-authentication-token-expiration'));
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stampHealthy(GitProviderToken|SocialAccount $token, ?Carbon $expiresAt): void
    {
        $token->update([
            'last_validated_at' => now(),
            'validation_error' => null,
            // Only overwrite a known expiry with a real one — a provider that
            // doesn't report expiry shouldn't erase what an earlier check knew.
            ...($expiresAt !== null ? ['expires_at' => $expiresAt] : []),
        ]);
    }

    private function stampRejected(GitProviderToken|SocialAccount $token, Response $response): void
    {
        $body = $response->json();
        $message = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? '') : '';

        $token->update([
            'validation_error' => sprintf(
                'HTTP %d%s',
                $response->status(),
                $message !== '' ? ' — '.mb_substr($message, 0, 200) : '',
            ),
        ]);
    }
}
