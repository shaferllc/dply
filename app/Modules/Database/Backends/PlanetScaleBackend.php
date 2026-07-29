<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Modules\Database\Services\PlanetScaleService;
use RuntimeException;

/**
 * PlanetScale (serverless MySQL) backend. BYO account, region-agnostic.
 *
 * The connection only exists once a branch password is created, so poll()
 * waits for the database to be `ready`, then mints a password on `main` exactly
 * once (guarded by whether a connection block is already stored).
 */
class PlanetScaleBackend implements DatabaseBackend
{
    public function key(): string
    {
        return CloudDatabase::BACKEND_PLANETSCALE;
    }

    public function supportedEngines(): array
    {
        return [CloudDatabase::ENGINE_MYSQL];
    }

    public function regionForServer(Server $server): ?string
    {
        return null;
    }

    public function estimatedMonthlyCost(string $size): ?int
    {
        return null;
    }

    public function provision(CloudDatabase $database): void
    {
        $name = $this->service($database)->createDatabase(
            $this->databaseName($database),
            $database->region !== '' ? $database->region : 'us-east',
        );

        $database->forceFill(['backend_id' => $name])->save();
    }

    public function poll(CloudDatabase $database): array
    {
        $service = $this->service($database);

        if (! is_string($database->backend_id) || $database->backend_id === '') {
            $this->provision($database);
        }

        $name = (string) $database->backend_id;
        $stored = $database->getAttribute('connection');
        $stored = is_array($stored) ? $stored : [];

        // Already have a connection (password minted) → ready.
        if ((string) ($stored['host'] ?? '') !== '') {
            return ['status' => 'online', 'connection' => $stored];
        }

        if ($service->databaseState($name) !== 'ready') {
            return ['status' => 'creating', 'connection' => []];
        }

        $conn = $service->createBranchPassword($name);
        $connection = [
            'host' => $conn['host'],
            'port' => '3306',
            'username' => $conn['username'],
            'password' => $conn['password'],
            'database' => $name,
            'ssl' => true,
        ];
        $database->forceFill(['connection' => $connection])->save();

        return ['status' => 'online', 'connection' => $connection];
    }

    public function lockNetworkTo(CloudDatabase $database, Server $server): void
    {
        // PlanetScale connections are TLS with per-branch credentials; no IP lock.
    }

    private function service(CloudDatabase $database): PlanetScaleService
    {
        $database->loadMissing('providerCredential');
        if ($database->providerCredential === null) {
            throw new RuntimeException('The database has no PlanetScale credential.');
        }

        return new PlanetScaleService($database->providerCredential);
    }

    private function databaseName(CloudDatabase $database): string
    {
        // PlanetScale names allow [a-z0-9-_]; reuse the requested logical name.
        return $database->name;
    }
}
