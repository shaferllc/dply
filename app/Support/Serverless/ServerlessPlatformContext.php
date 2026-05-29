<?php

declare(strict_types=1);

namespace App\Support\Serverless;

use RuntimeException;

/**
 * OpenWhisk credentials for dply's own managed DigitalOcean Functions
 * namespace — the FaaS counterpart to {@see App\Support\Edge\EdgeDeliveryContext}
 * `platform()`.
 *
 * In managed mode dply deploys customer functions into a shared, pre-provisioned
 * dply namespace (dply pays DO), rather than the customer's own credential. The
 * managed create option is only offered when these credentials are configured.
 */
final readonly class ServerlessPlatformContext
{
    public function __construct(
        public string $apiHost,
        public string $namespace,
        public string $accessKey,
        public string $region,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiHost: trim((string) config('serverless.managed.api_host', '')),
            namespace: trim((string) config('serverless.managed.namespace', '')),
            accessKey: trim((string) config('serverless.managed.access_key', '')),
            region: trim((string) config('serverless.managed.region', 'nyc1')) ?: 'nyc1',
        );
    }

    /**
     * True when dply's platform namespace is fully configured and the managed
     * option can be offered/provisioned.
     */
    public function configured(): bool
    {
        return $this->apiHost !== '' && $this->namespace !== '' && $this->accessKey !== '';
    }

    /**
     * The OpenWhisk credentials block written to `server.meta.digitalocean_functions`
     * so the existing DO Functions deploy engine talks to dply's namespace.
     *
     * @return array{api_host: string, namespace: string, access_key: string}
     */
    public function openWhiskCredentials(): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('dply-managed serverless is not configured. Set DPLY_SERVERLESS_DO_* in the environment.');
        }

        return [
            'api_host' => $this->apiHost,
            'namespace' => $this->namespace,
            'access_key' => $this->accessKey,
        ];
    }
}
