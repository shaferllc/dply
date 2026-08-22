<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Actions;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Modules\Cloud\Jobs\ProvisionCloudDatabaseJob;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Support\Servers\ProviderManagedDatabaseRegion;
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
     * DO provider keys that can authenticate against the Managed Databases
     * API. Just `digitalocean` now — the old App Platform credential type
     * was unified into this one.
     *
     * @var list<string>
     */
    private const DO_PROVIDERS = ['digitalocean'];

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

        $credential = $this->resolveCredential($organization);
        if ($credential === null) {
            throw new RuntimeException(
                'No DigitalOcean credential connected. Connect a DigitalOcean credential first.',
            );
        }

        try {
            $service = new DigitalOceanService($credential);
            $availableRegions = $service->getDatabaseEngineRegions($engine);
            $availableSizes = $service->getDatabaseEngineSizes($engine);
            $availableVersions = $service->getDatabaseEngineVersions($engine);
            $defaultVersion = $service->getDatabaseEngineDefaultVersion($engine);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Could not load DigitalOcean\'s database catalog: '.$e->getMessage(),
                previous: $e,
            );
        }

        if ($availableRegions === []) {
            throw new RuntimeException(
                'DigitalOcean did not return any regions for this engine. Try again in a moment.',
            );
        }

        if ($availableSizes === []) {
            throw new RuntimeException(
                'DigitalOcean did not return any sizes for this engine. Try again in a moment.',
            );
        }

        $inputRegion = trim((string) ($payload['region'] ?? ''));
        $region = ProviderManagedDatabaseRegion::resolve(
            'digitalocean',
            $inputRegion !== '' ? $inputRegion : null,
            null,
            $availableRegions,
        );
        if ($region === null) {
            throw new RuntimeException(
                'Could not pick a DigitalOcean region for this engine.',
            );
        }

        $size = $this->resolveCatalogSize(
            strtolower(trim((string) ($payload['size'] ?? ''))),
            $availableSizes,
        );

        $version = trim((string) ($payload['version'] ?? ''));
        if ($version === '' || ($availableVersions !== [] && ! in_array($version, $availableVersions, true))) {
            $version = $defaultVersion ?? ($availableVersions[0] ?? $version);
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

    /**
     * @param  list<string>  $available
     */
    private function resolveCatalogSize(string $size, array $available): string
    {
        if ($size !== '' && array_key_exists($size, CloudDatabase::SIZE_TIERS)) {
            $mapped = CloudDatabase::SIZE_TIERS[$size];
            if (in_array($mapped, $available, true)) {
                return $mapped;
            }
        }

        if ($size !== '' && in_array($size, $available, true)) {
            return $size;
        }

        return $available[0];
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
