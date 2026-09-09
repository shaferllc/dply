<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Providers\Services\DigitalOceanService;

/**
 * Live managed-database regions for the server's provider account.
 *
 * The droplet catalog is a superset — listing it lets operators pick a slug
 * the database create API then rejects. Always prefer the provider's
 * database-options catalog for the chosen engine.
 */
final class ManagedDatabaseRegionCatalog
{
    /**
     * @param  list<string>  $rejected
     * @return list<string>
     */
    public static function slugs(Server $server, string $engine, ?ProviderCredential $credential = null, array $rejected = []): array
    {
        $slugs = ManagedDatabaseCatalogAuth::firstSuccessful(
            $server,
            $credential,
            static fn (DigitalOceanService $service): array => $service->getDatabaseEngineRegions($engine),
        );

        if (! is_array($slugs) || $slugs === []) {
            return [];
        }

        return ProviderManagedDatabaseRegion::filterForEngine($engine, $slugs, $rejected);
    }

    /**
     * @param  list<string>  $rejected
     * @return list<array{value: string, label: string}>
     */
    public static function options(Server $server, string $engine, ?ProviderCredential $credential = null, array $rejected = []): array
    {
        return ProviderManagedDatabaseRegion::options(
            $server->provider->value,
            self::slugs($server, $engine, $credential, $rejected),
        );
    }

    public static function lastError(): ?string
    {
        return ManagedDatabaseCatalogFailure::lastError();
    }

    public static function operatorMessage(): ?string
    {
        return ManagedDatabaseCatalogFailure::operatorMessage();
    }
}
