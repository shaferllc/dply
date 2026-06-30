<?php

declare(strict_types=1);

namespace App\Modules\Database\Support;

use App\Enums\ServerProvider;
use App\Models\Server;

/**
 * Static facts for the "dedicated database VM" placement: which engines a
 * dedicated box can run, how a dply engine maps to the provisioner's
 * `database` form value, and whether a given server is eligible to spawn one
 * (a cloud-API provider with a connected credential — custom/BYO boxes can't
 * have a sibling provisioned for them).
 */
final class DedicatedDatabaseVm
{
    /** dply engine → server-provision `database` install value. */
    public const ENGINE_FORMATS = [
        'mysql' => 'mysql84',
        'postgres' => 'postgres16',
        // ClickHouse installs from its own apt repo (unversioned); the dedicated
        // DB server provision config bootstraps it (DedicatedDatabaseServerProvisionConfig).
        'clickhouse' => 'clickhouse',
    ];

    /** @return list<string> */
    public static function supportedEngines(): array
    {
        return array_keys(self::ENGINE_FORMATS);
    }

    public static function engineFormat(string $engine): string
    {
        return self::ENGINE_FORMATS[$engine] ?? 'mysql84';
    }

    /**
     * Eligible when the app server runs on a cloud-API provider we can
     * provision against (not custom/BYO) and has a connected credential to
     * bill the new box to (mirrors how the app server itself was created).
     */
    public static function eligible(Server $server): bool
    {
        return $server->provider !== ServerProvider::Custom
            && filled($server->provider_credential_id);
    }
}
