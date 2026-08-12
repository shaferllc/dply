<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Support\Servers\DedicatedDatabaseServerProvisionConfig;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Normalizes wizard “stack” fields into servers.meta for setup scripts.
 */
final class BuildServerProvisionMeta
{
    use AsObject;

    /**
     * @param  array<string, string>  $runtimeDefaults  Per-language version
     *                                                  pins (e.g. ['ruby' => '3.3', 'node' => '22']).
     *                                                  Keys with empty-string values are dropped.
     * @return array<string, mixed>
     */
    public function handle(
        string $installProfile,
        string $serverRole,
        string $cacheService,
        string $webserver,
        string $phpVersion,
        string $database,
        array $runtimeDefaults = [],
        bool $cacheRemoteAccess = false,
        string $cacheAllowedFrom = '',
        bool $cacheRequirePassword = false,
        ?string $cachePassword = null,
        bool $databaseRemoteAccess = false,
        string $databaseAllowedFrom = '',
        string $databaseInitialName = 'app',
        string $databaseUsername = 'dply_app',
        ?string $databasePassword = null,
    ): array {
        $meta = [
            'install_profile' => $installProfile,
            'server_role' => $serverRole,
            'cache_service' => $cacheService,
            'webserver' => $webserver,
            'php_version' => $phpVersion,
            'database' => $database,
        ];

        $cleaned = array_filter($runtimeDefaults, static fn ($v) => is_string($v) && $v !== '');
        if ($cleaned !== []) {
            $meta['runtime_defaults'] = $cleaned;
        }

        if ($serverRole === 'redis' && ($cacheRemoteAccess || $cacheRequirePassword)) {
            $cacheServerMeta = [
                'remote_access' => $cacheRemoteAccess,
                'allowed_from' => trim($cacheAllowedFrom),
                'require_password' => $cacheRequirePassword,
            ];

            if ($cacheRequirePassword && is_string($cachePassword) && $cachePassword !== '') {
                $cacheServerMeta['password_encrypted'] = Crypt::encryptString($cachePassword);
            }

            $meta['cache_server'] = $cacheServerMeta;
        }

        // A Docker host with an engine selected runs it as a container, and the
        // mysql/postgres images refuse to initialise without a superuser
        // password — so it needs the same bootstrap credentials a dedicated
        // database node gets. Without this the container step has nothing to
        // pass to -e POSTGRES_PASSWORD and the provision fails.
        $wantsBootstrapCredentials = $serverRole === 'database'
            || ($serverRole === 'docker' && $database !== 'none' && trim($database) !== '');

        if ($wantsBootstrapCredentials && DedicatedDatabaseServerProvisionConfig::supportsBootstrapCredentials($database)) {
            $databaseServerMeta = [
                'remote_access' => $databaseRemoteAccess,
                'allowed_from' => trim($databaseAllowedFrom),
                'database_name' => trim($databaseInitialName),
                'username' => trim($databaseUsername),
            ];

            // The wizard only generates a password for the dedicated-database
            // role (StepWhat::updatedFormDatabase() is gated on it), so the
            // Docker path arrives with none. Mint one here rather than letting
            // the container step fail: this is the choke point every caller
            // (wizard, warm pool, managed server) already passes through, and
            // meta is where the credential is stored anyway.
            if (! is_string($databasePassword) || $databasePassword === '') {
                $databasePassword = Str::password(32, symbols: false);
            }

            $databaseServerMeta['password_encrypted'] = Crypt::encryptString($databasePassword);

            $meta['database_server'] = $databaseServerMeta;
        }

        return $meta;
    }
}
