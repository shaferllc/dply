<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Enums\ServerProvider;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;

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
        if ($server->provider !== ServerProvider::DigitalOcean) {
            return [];
        }

        $credential ??= $server->providerCredential;
        if (! $credential instanceof ProviderCredential) {
            $server->loadMissing('providerCredential');
            $credential = $server->providerCredential;
        }

        if (! $credential instanceof ProviderCredential) {
            return [];
        }

        try {
            $slugs = (new DigitalOceanService($credential))->getDatabaseEngineRegions($engine);
        } catch (\Throwable) {
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
}
