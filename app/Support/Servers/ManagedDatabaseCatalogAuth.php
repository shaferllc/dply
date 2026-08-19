<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Enums\ServerProvider;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;

/**
 * Tokens to try for the managed-database catalog. Prefer the app
 * DIGITALOCEAN_TOKEN (services.digitalocean.token), then org credentials.
 * The server's attached credential is often stale locally.
 */
final class ManagedDatabaseCatalogAuth
{
    /**
     * @template T
     *
     * @param  callable(DigitalOceanService): T  $fetch
     * @return T|list<never>
     */
    public static function firstSuccessful(Server $server, ?ProviderCredential $preferred, callable $fetch): mixed
    {
        if ($server->provider !== ServerProvider::DigitalOcean) {
            return [];
        }

        ManagedDatabaseCatalogFailure::clear();

        $last = null;
        foreach (self::candidates($server, $preferred) as $candidate) {
            try {
                $result = $fetch(new DigitalOceanService($candidate));
            } catch (\Throwable $exception) {
                $last = $exception;

                continue;
            }

            if (! is_array($result) || $result === []) {
                continue;
            }

            if ($candidate instanceof ProviderCredential) {
                ManagedDatabaseCatalogFailure::rememberWorkingCredential($candidate);
            }

            return $result;
        }

        if ($last !== null) {
            ManagedDatabaseCatalogFailure::remember($last, $server->provider->value);
        }

        return [];
    }

    /**
     * Credential that can actually read the catalog. Never the platform
     * token — creating a cluster must bill a customer-connected account.
     */
    public static function resolveCredential(Server $server, ?ProviderCredential $preferred = null): ?ProviderCredential
    {
        $remembered = ManagedDatabaseCatalogFailure::workingCredential();
        if ($remembered instanceof ProviderCredential) {
            return $remembered;
        }

        foreach (self::candidates($server, $preferred) as $candidate) {
            if (! $candidate instanceof ProviderCredential) {
                continue;
            }

            try {
                $regions = (new DigitalOceanService($candidate))->getDatabaseEngineRegions('postgres');
            } catch (\Throwable) {
                continue;
            }

            if ($regions === []) {
                continue;
            }

            ManagedDatabaseCatalogFailure::rememberWorkingCredential($candidate);

            return $candidate;
        }

        $server->loadMissing('providerCredential');

        return $preferred ?? $server->providerCredential;
    }

    /**
     * @return list<ProviderCredential|non-empty-string>
     */
    public static function candidates(Server $server, ?ProviderCredential $preferred = null): array
    {
        $seen = [];
        $out = [];

        $add = static function (ProviderCredential|string|null $candidate) use (&$seen, &$out): void {
            if ($candidate instanceof ProviderCredential) {
                $key = 'id:'.$candidate->id;
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;
                $out[] = $candidate;

                return;
            }

            $token = is_string($candidate) ? trim($candidate) : '';
            if ($token === '') {
                return;
            }

            $key = 'tok:'.sha1($token);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = $token;
        };

        $add(self::appToken());
        $add($preferred);

        $server->loadMissing('providerCredential');
        $add($server->providerCredential);

        $orgId = $server->organization_id;
        if ($orgId !== null) {
            foreach (ProviderCredential::query()
                ->where('organization_id', $orgId)
                ->where('provider', $server->provider->value)
                ->orderBy('created_at')
                ->get() as $credential) {
                $add($credential);
            }
        }

        return $out;
    }

    public static function appToken(): string
    {
        foreach ([
            config('services.digitalocean.token'),
            config('dply.digitalocean_token'),
        ] as $token) {
            $token = is_string($token) ? trim($token) : '';
            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }
}
