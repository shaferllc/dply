<?php

declare(strict_types=1);

namespace App\Support\Cloud;

use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class AzureAccessToken
{
    /**
     * @return array{tenant_id:string, client_id:string, client_secret:string, subscription_id:string}
     */
    public static function credentials(ProviderCredential $credential): array
    {
        $creds = is_array($credential->credentials) ? $credential->credentials : [];

        $tenantId = trim((string) ($creds['tenant_id'] ?? ''));
        $clientId = trim((string) ($creds['client_id'] ?? ''));
        $clientSecret = trim((string) ($creds['client_secret'] ?? ''));
        $subscriptionId = trim((string) ($creds['subscription_id'] ?? ''));

        if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $subscriptionId === '') {
            throw new \InvalidArgumentException('Azure credentials require tenant_id, client_id, client_secret, and subscription_id.');
        }

        return [
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'subscription_id' => $subscriptionId,
        ];
    }

    public static function bearerToken(ProviderCredential $credential): string
    {
        $creds = self::credentials($credential);
        $cacheKey = sprintf('azure:oauth-token:%s:%s:%s', $credential->id, $creds['tenant_id'], $creds['client_id']);

        /** @var string $token */
        $token = Cache::remember($cacheKey, now()->addMinutes(50), function () use ($creds): string {
            $response = Http::asForm()
                ->acceptJson()
                ->post('https://login.microsoftonline.com/'.$creds['tenant_id'].'/oauth2/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $creds['client_id'],
                    'client_secret' => $creds['client_secret'],
                    'resource' => 'https://management.azure.com/',
                ]);

            if (! $response->successful()) {
                $msg = (string) ($response->json('error_description') ?? $response->json('error.message') ?? $response->body());
                throw new \RuntimeException('Azure OAuth token request failed: '.trim($msg));
            }

            $accessToken = trim((string) $response->json('access_token'));
            if ($accessToken === '') {
                throw new \RuntimeException('Azure OAuth token request returned no access_token.');
            }

            return $accessToken;
        });

        return $token;
    }
}
