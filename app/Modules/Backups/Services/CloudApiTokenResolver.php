<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Models\BackupConfiguration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolve the bearer token a cloud-drive upload needs, in the control plane.
 *
 * This runs here rather than on the server for a reason: Google's long-lived
 * secret is the `client_secret` + `refresh_token` pair, and an access token
 * minted from it expires in about an hour. Exchanging here means only the
 * short-lived token ever reaches a server, so a compromised box leaks an hour
 * of access rather than permanent access to the customer's Drive.
 *
 * Dropbox stores a token directly, so there is nothing to exchange — but the
 * same seam lets a refresh-token flow slot in later without touching the
 * exporter.
 */
final class CloudApiTokenResolver
{
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const DROPBOX_TOKEN_URL = 'https://api.dropbox.com/oauth2/token';

    /** Google access tokens last ~3600s; expire ours early so a slow upload can't start on a dead token. */
    private const GOOGLE_TOKEN_TTL_SECONDS = 3000;

    /** @var array<string, array{token: string, expires_at: int}> */
    private array $memo = [];

    public function forConfiguration(BackupConfiguration $configuration): string
    {
        return match ($configuration->provider) {
            BackupConfiguration::PROVIDER_DROPBOX => $this->dropboxToken($configuration),
            BackupConfiguration::PROVIDER_GOOGLE_DRIVE => $this->googleToken($configuration),
            default => throw new RuntimeException('No cloud-drive token for provider: '.$configuration->provider),
        };
    }

    /**
     * Dropbox accepts either shape:
     *
     * - app key + secret + refresh token — the durable one, and the only one
     *   suitable for a schedule. Exchanged here so the app secret stays in the
     *   control plane, same as Google.
     * - a bare access token — what the App Console's "Generate" button hands
     *   you. Those expire in about 4 hours now, so it is fine for a one-off
     *   test and wrong for anything recurring.
     */
    private function dropboxToken(BackupConfiguration $configuration): string
    {
        $config = $configuration->config ?? [];
        $refreshToken = trim((string) ($config['refresh_token'] ?? ''));
        $appKey = trim((string) ($config['app_key'] ?? ''));
        $appSecret = trim((string) ($config['app_secret'] ?? ''));

        if ($refreshToken !== '' && $appKey !== '' && $appSecret !== '') {
            return $this->dropboxRefreshedToken($configuration, $appKey, $appSecret, $refreshToken);
        }

        $token = trim((string) ($config['access_token'] ?? ''));

        if ($token === '') {
            throw new RuntimeException(__('This Dropbox destination has no credentials. Edit it and add either a refresh token or an access token.'));
        }

        return $token;
    }

    private function dropboxRefreshedToken(
        BackupConfiguration $configuration,
        string $appKey,
        string $appSecret,
        string $refreshToken,
    ): string {
        $key = 'dropbox:'.$configuration->id;
        $cached = $this->memo[$key] ?? null;
        if ($cached !== null && $cached['expires_at'] > time()) {
            return $cached['token'];
        }

        $response = Http::asForm()
            ->withBasicAuth($appKey, $appSecret)
            ->timeout(20)
            ->post(self::DROPBOX_TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if (! $response->successful()) {
            $detail = (string) ($response->json('error_description') ?? $response->json('error_summary') ?? '');

            throw new RuntimeException(__('Dropbox rejected the refresh token: :detail', [
                'detail' => $detail !== '' ? $detail : 'HTTP '.$response->status(),
            ]));
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException(__('Dropbox returned no access token.'));
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 0);
        $ttl = $expiresIn > 0 ? min($expiresIn - 60, self::GOOGLE_TOKEN_TTL_SECONDS) : self::GOOGLE_TOKEN_TTL_SECONDS;

        $this->memo[$key] = ['token' => $token, 'expires_at' => time() + max(60, $ttl)];

        return $token;
    }

    private function googleToken(BackupConfiguration $configuration): string
    {
        $config = $configuration->config ?? [];
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $refreshToken = trim((string) ($config['refresh_token'] ?? ''));

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new RuntimeException(__('This Google Drive destination is missing its client id, secret, or refresh token.'));
        }

        // One exchange per configuration per request: a prune run touching many
        // backups on one destination shouldn't hit Google once per artifact.
        $key = 'google:'.$configuration->id;
        $cached = $this->memo[$key] ?? null;
        if ($cached !== null && $cached['expires_at'] > time()) {
            return $cached['token'];
        }

        $response = Http::asForm()
            ->timeout(20)
            ->post(self::GOOGLE_TOKEN_URL, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

        if (! $response->successful()) {
            // Google puts the useful part in `error_description`; surfacing the
            // raw body would dump the request echo into a console transcript.
            $detail = (string) ($response->json('error_description') ?? $response->json('error') ?? '');

            throw new RuntimeException(__('Google Drive rejected the refresh token: :detail', [
                'detail' => $detail !== '' ? $detail : 'HTTP '.$response->status(),
            ]));
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException(__('Google Drive returned no access token.'));
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 0);
        $ttl = $expiresIn > 0 ? min($expiresIn - 60, self::GOOGLE_TOKEN_TTL_SECONDS) : self::GOOGLE_TOKEN_TTL_SECONDS;

        $this->memo[$key] = [
            'token' => $token,
            'expires_at' => time() + max(60, $ttl),
        ];

        return $token;
    }
}
