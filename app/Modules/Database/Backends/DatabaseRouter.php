<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Enums\ServerProvider;
use App\Models\CloudDatabase;
use App\Models\Server;
use InvalidArgumentException;

/**
 * Selects the {@see DatabaseBackend} for a backend key or a server's provider.
 *
 * Mirrors the Cloud module's {@see \App\Modules\Cloud\Backends\CloudRouter}:
 * a static map of backend key → implementation, plus a "what can co-locate
 * with this server" lookup the modal uses to decide whether to surface a
 * managed-cluster card next to the on-box option.
 */
class DatabaseRouter
{
    /**
     * The co-located managed backend per server provider. Only providers with
     * a same-region managed-DB product appear here; everything else (Hetzner,
     * unknown/imported) has no co-located option and falls back to on-box +
     * (later) BYO serverless vendors.
     *
     * @var array<string, class-string<DatabaseBackend>>
     */
    private const COLOCATED = [
        ServerProvider::DigitalOcean->value => DoManagedBackend::class,
        ServerProvider::Vultr->value => VultrManagedBackend::class,
        // ServerProvider::Aws->value   => AwsRdsBackend::class,         // Phase 4
    ];

    /**
     * Backend by its persisted key (CloudDatabase.backend).
     *
     * @var array<string, class-string<DatabaseBackend>>
     */
    private const BY_KEY = [
        CloudDatabase::BACKEND_DIGITALOCEAN => DoManagedBackend::class,
        CloudDatabase::BACKEND_VULTR => VultrManagedBackend::class,
    ];

    public function backend(string $key): DatabaseBackend
    {
        $class = self::BY_KEY[$key] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("Unknown database backend [{$key}].");
        }

        return app($class);
    }

    public function backendFor(CloudDatabase $database): DatabaseBackend
    {
        return $this->backend($database->backend);
    }

    /**
     * The managed backend that can co-locate a cluster with $server, or null
     * when its provider has no same-region managed-DB offering.
     */
    public function colocatedBackendFor(Server $server): ?DatabaseBackend
    {
        $class = self::COLOCATED[$server->provider->value] ?? null;

        return $class !== null ? app($class) : null;
    }
}
