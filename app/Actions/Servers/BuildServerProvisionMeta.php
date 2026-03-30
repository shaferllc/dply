<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;

/**
 * Normalizes wizard “stack” fields into servers.meta for setup scripts.
 */
final class BuildServerProvisionMeta
{
    use AsObject;

    /**
     * @return array<string, string>
     */
    public function handle(
        string $serverRole,
        string $cacheService,
        string $webserver,
        string $phpVersion,
        string $database,
    ): array {
        return [
            'server_role' => $serverRole,
            'cache_service' => $cacheService,
            'webserver' => $webserver,
            'php_version' => $phpVersion,
            'database' => $database,
        ];
    }
}
