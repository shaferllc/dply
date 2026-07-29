<?php

declare(strict_types=1);

namespace App\Services\ProductionData;

use App\Models\ProductionDataConnection;
use App\Support\Cloud\CloudIndexRow;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin Bearer client against a remote dply control plane (`/api/v1…`).
 * Used by the local-only Production data mirror — never called from prod APP_ENV.
 */
class ProductionApiClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $token,
    ) {}

    public static function forConnection(ProductionDataConnection $connection): self
    {
        return new self($connection->base_url, $connection->api_token);
    }

    public static function unauthenticated(string $baseUrl): self
    {
        return new self($baseUrl, '');
    }

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}
     */
    public function startDeviceAuthorization(): array
    {
        $response = $this->rawClient()->post('/auth/device/start');
        $this->throwUnlessSuccessful($response, 'device start');

        /** @var array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int} */
        return $response->json();
    }

    /**
     * @return array{status: string, token?: string}
     */
    public function pollDeviceAuthorization(string $deviceCode): array
    {
        $response = $this->rawClient()->post('/auth/device/poll', [
            'device_code' => $deviceCode,
        ]);
        $this->throwUnlessSuccessful($response, 'device poll');

        /** @var array{status: string, token?: string} */
        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function account(): array
    {
        return $this->getJson('/account');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sites(): array
    {
        $payload = $this->getJson('/sites');

        return array_values($payload['data'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function site(string $siteId): array
    {
        $payload = $this->getJson('/sites/'.$siteId);

        return $payload['data'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deployments(string $siteId, int $limit = 20): array
    {
        $payload = $this->getJson('/sites/'.$siteId.'/deployments', ['limit' => $limit]);

        return array_values($payload['data'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function deployment(string $siteId, string $deploymentId): array
    {
        $payload = $this->getJson('/sites/'.$siteId.'/deployments/'.$deploymentId);

        return $payload['data'] ?? [];
    }

    /**
     * @return array{message?: string}
     */
    public function deploy(string $siteId): array
    {
        $response = $this->authedClient()->post('/sites/'.$siteId.'/deploy');
        $this->throwUnlessSuccessful($response, 'deploy', [200, 202]);

        /** @var array{message?: string} */
        return $response->json() ?? ['message' => 'Deployment queued.'];
    }

    public function envContent(string $siteId): string
    {
        $payload = $this->getJson('/sites/'.$siteId.'/env/content');

        return (string) ($payload['data']['content'] ?? '');
    }

    public function putEnvContent(string $siteId, string $content): void
    {
        $response = $this->authedClient()->put('/sites/'.$siteId.'/env/content', [
            'content' => $content,
        ]);
        $this->throwUnlessSuccessful($response, 'env content put');
    }

    /**
     * @param  array{name?: string, git_branch?: string|null}  $attributes
     * @return array<string, mixed>
     */
    public function updateSite(string $siteId, array $attributes): array
    {
        $response = $this->authedClient()->patch('/sites/'.$siteId, $attributes);
        $this->throwUnlessSuccessful($response, 'site update');

        /** @var array{data?: array<string, mixed>} $payload */
        $payload = $response->json() ?? [];

        return $payload['data'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function servers(): array
    {
        $payload = $this->getJson('/servers');

        return array_values($payload['data'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function projects(): array
    {
        $payload = $this->getJson('/account/projects');

        return array_values($payload['data'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function edgeSites(): array
    {
        $payload = $this->getJson('/edge/sites');

        return array_values($payload['data'] ?? []);
    }

    /**
     * Cloud apps inventory — container sites from the general sites index
     * until a dedicated `/cloud/sites` control-plane surface exists.
     *
     * @return list<array<string, mixed>>
     */
    public function cloudSites(): array
    {
        return array_values(array_filter(
            $this->sites(),
            static fn (array $row): bool => CloudIndexRow::isCloudInventoryRow($row),
        ));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $response = $this->authedClient()->get($path, $query);
        $this->throwUnlessSuccessful($response, $path);

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }

    protected function apiRoot(): string
    {
        return rtrim($this->baseUrl, '/').'/api/v1';
    }

    protected function rawClient(): PendingRequest
    {
        return Http::baseUrl($this->apiRoot())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('dply.production_data_mirror.http_timeout_seconds', 30));
    }

    protected function authedClient(): PendingRequest
    {
        if ($this->token === '') {
            throw new ProductionApiException('Production API token is missing.', 401);
        }

        return $this->rawClient()->withToken($this->token);
    }

    /**
     * @param  list<int>  $ok
     */
    protected function throwUnlessSuccessful(Response $response, string $context, array $ok = [200]): void
    {
        if (in_array($response->status(), $ok, true)) {
            return;
        }

        $json = $response->json();
        $message = is_array($json) && isset($json['message']) && is_string($json['message'])
            ? $json['message']
            : sprintf('Production API %s failed (HTTP %d).', $context, $response->status());

        throw new ProductionApiException(
            $message,
            $response->status(),
            is_array($json) ? $json : null,
        );
    }
}
