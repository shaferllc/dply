<?php

declare(strict_types=1);

namespace Dply\Core\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * OAuth 2 authorization-code + PKCE client for {@see https://auth.dply.io} (Laravel Passport).
 */
final class CentralOAuthClient
{
    private Client $http;

    public function __construct(
        private readonly string $authBaseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['http_errors' => false, 'timeout' => 30]);
    }

    /**
     * Build the authorize URL (browser redirect). Caller stores $state and $codeVerifier in session.
     *
     * @param  non-empty-string  $state
     */
    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'read-user',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return rtrim($this->authBaseUrl, '/').'/oauth/authorize?'.$query;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string}
     */
    public function exchangeAuthorizationCode(string $code, string $codeVerifier): array
    {
        $url = rtrim($this->authBaseUrl, '/').'/oauth/token';

        try {
            $response = $this->http->post($url, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $this->redirectUri,
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new CentralOAuthException('Token request failed: '.$e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CentralOAuthException('Invalid JSON from token endpoint: '.$body, 0, $e);
        }

        if ($response->getStatusCode() >= 400) {
            $hint = $data['message'] ?? $data['error'] ?? $body;

            throw new CentralOAuthException('Token endpoint error: '.(string) $hint);
        }

        if (empty($data['access_token']) || ! is_string($data['access_token'])) {
            throw new CentralOAuthException('Token response missing access_token.');
        }

        /** @var array{access_token: string, refresh_token?: string, expires_in?: int, token_type?: string} */
        return [
            'access_token' => $data['access_token'],
            'refresh_token' => isset($data['refresh_token']) && is_string($data['refresh_token']) ? $data['refresh_token'] : null,
            'expires_in' => isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : null,
            'token_type' => isset($data['token_type']) && is_string($data['token_type']) ? $data['token_type'] : null,
        ];
    }

    /**
     * @return array{id: int|string, name: string, email: string, email_verified_at: ?string}
     */
    public function fetchUserProfile(string $accessToken): array
    {
        $url = rtrim($this->authBaseUrl, '/').'/api/user';

        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$accessToken,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new CentralOAuthException('User profile request failed: '.$e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CentralOAuthException('Invalid JSON from /api/user: '.$body, 0, $e);
        }

        if ($response->getStatusCode() >= 400) {
            throw new CentralOAuthException('User profile error: '.$body);
        }

        foreach (['id', 'name', 'email'] as $key) {
            if (empty($data[$key]) || ! is_scalar($data[$key])) {
                throw new CentralOAuthException('User profile missing '.$key);
            }
        }

        $verified = $data['email_verified_at'] ?? null;

        return [
            'id' => $data['id'],
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'email_verified_at' => is_string($verified) ? $verified : null,
        ];
    }
}
