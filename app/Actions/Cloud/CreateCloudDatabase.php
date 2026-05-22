<?php

declare(strict_types=1);

namespace App\Actions\Cloud;

use App\Jobs\ProvisionCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use InvalidArgumentException;
use RuntimeException;

/**
 * Creates a CloudDatabase row representing a managed database on the
 * dply cloud platform, then dispatches the provision job that talks to
 * the DigitalOcean Managed Databases API.
 *
 * The row is created in STATUS_PROVISIONING; ProvisionCloudDatabaseJob
 * polls the DO cluster until it reports `online` and fills in the
 * encrypted connection block.
 */
class CreateCloudDatabase
{
    private const ENGINES = [
        CloudDatabase::ENGINE_POSTGRES,
        CloudDatabase::ENGINE_MYSQL,
        CloudDatabase::ENGINE_REDIS,
    ];

    /**
     * DO provider keys that can authenticate against the Managed
     * Databases API, in preference order. The plain `digitalocean`
     * credential is the canonical one; the App Platform credential is
     * accepted as a fallback since it carries the same API token.
     *
     * @var list<string>
     */
    private const DO_PROVIDERS = ['digitalocean', 'digitalocean_app_platform'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Organization $organization, array $payload): CloudDatabase
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A database name is required.');
        }

        $engine = strtolower(trim((string) ($payload['engine'] ?? '')));
        if (! in_array($engine, self::ENGINES, true)) {
            throw new InvalidArgumentException(
                'Unknown engine. Use one of: '.implode(', ', self::ENGINES),
            );
        }

        $size = strtolower(trim((string) ($payload['size'] ?? 'small')));
        if (! array_key_exists($size, CloudDatabase::SIZE_TIERS)) {
            $size = 'small';
        }

        $region = trim((string) ($payload['region'] ?? '')) ?: 'nyc1';
        $version = trim((string) ($payload['version'] ?? ''));

        $credential = $this->resolveCredential($organization);
        if ($credential === null) {
            throw new RuntimeException(
                'No DigitalOcean credential connected. Connect a DigitalOcean credential first.',
            );
        }

        $database = CloudDatabase::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'engine' => $engine,
            'version' => $version,
            'size' => $size,
            'region' => $region,
            'backend' => CloudDatabase::BACKEND_DIGITALOCEAN,
            'provider_credential_id' => $credential->id,
            'status' => CloudDatabase::STATUS_PROVISIONING,
        ]);

        ProvisionCloudDatabaseJob::dispatch($database->id);

        return $database;
    }

    private function resolveCredential(Organization $organization): ?ProviderCredential
    {
        foreach (self::DO_PROVIDERS as $provider) {
            $credential = ProviderCredential::query()
                ->where('organization_id', $organization->id)
                ->where('provider', $provider)
                ->orderBy('created_at')
                ->first();
            if ($credential !== null) {
                return $credential;
            }
        }

        return null;
    }
}
