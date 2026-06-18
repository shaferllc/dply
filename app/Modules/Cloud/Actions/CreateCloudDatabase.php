<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Actions;

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

        $size = strtolower(trim((string) ($payload['size'] ?? 'small')));
        if (! array_key_exists($size, CloudDatabase::SIZE_TIERS)) {
            $size = 'small';
        }

        $inputRegion = trim((string) ($payload['region'] ?? ''));
        $region = $inputRegion !== '' ? self::normalizeRegionForManagedDb($inputRegion) : 'nyc1';
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

    /**
     * App Platform exposes short region codes (`ams`, `nyc`, `fra`); the
     * Managed Databases API requires numbered slugs (`ams3`, `nyc3`,
     * `fra1`). When a Cloud-site-with-DB deploy flows the App Platform
     * region straight through to the DB create call, DO 400s with
     * "region 'ams' is not valid for 'PG' cluster type". Normalize here
     * so all callers (Cloud site extras, standalone DB page, CLI) end up
     * with the slug DO actually accepts. Slugs that are already in the
     * DB taxonomy (e.g. `ams3` from the standalone form) pass through.
     *
     * For NYC and SFO the canonical Managed DB region uses the newer
     * datacenter (`nyc3`, `sfo3`) — both engines we ship (postgres,
     * mysql, redis) run there, while `nyc1` is older and only supports
     * a subset.
     *
     * Source: https://docs.digitalocean.com/products/regional-availability/
     */
    private static function normalizeRegionForManagedDb(string $region): string
    {
        $region = strtolower(trim($region));

        // Already a DB-style slug (e.g. "ams3", "nyc1") — pass through.
        if (preg_match('/^[a-z]{3}[0-9]$/', $region) === 1) {
            return $region;
        }

        return match ($region) {
            'ams' => 'ams3',
            'nyc' => 'nyc3',
            'fra' => 'fra1',
            'sfo' => 'sfo3',
            'sgp' => 'sgp1',
            'lon' => 'lon1',
            'tor' => 'tor1',
            'blr' => 'blr1',
            'syd' => 'syd1',
            default => $region,
        };
    }
}
