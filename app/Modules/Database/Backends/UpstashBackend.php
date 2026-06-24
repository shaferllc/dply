<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Modules\Database\Services\UpstashService;
use RuntimeException;

/**
 * Upstash (serverless Redis) backend. BYO account, region-agnostic.
 *
 * Upstash returns the endpoint/port/password synchronously at create time, so
 * provisioning completes in a single step — poll() reports online as soon as
 * the connection is stored.
 */
class UpstashBackend implements DatabaseBackend
{
    public function key(): string
    {
        return CloudDatabase::BACKEND_UPSTASH;
    }

    public function supportedEngines(): array
    {
        return [CloudDatabase::ENGINE_REDIS];
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
        $conn = $this->service($database)->createDatabase(
            $database->name,
            $database->region !== '' ? $database->region : 'us-east-1',
        );

        $database->forceFill([
            'backend_id' => $conn['host'],
            'connection' => [
                'host' => $conn['host'],
                'port' => $conn['port'],
                'username' => '',
                'password' => $conn['password'],
                'database' => '',
                'ssl' => true,
            ],
        ])->save();
    }

    public function poll(CloudDatabase $database): array
    {
        if (! is_string($database->backend_id) || $database->backend_id === '') {
            $this->provision($database);
        }

        $connection = $database->getAttribute('connection');
        $connection = is_array($connection) ? $connection : [];
        $ready = (string) ($connection['host'] ?? '') !== '';

        return ['status' => $ready ? 'online' : 'creating', 'connection' => $connection];
    }

    public function lockNetworkTo(CloudDatabase $database, Server $server): void
    {
        // Upstash is accessed over TLS with a token; no IP lockdown in v1.
    }

    private function service(CloudDatabase $database): UpstashService
    {
        $database->loadMissing('providerCredential');
        if ($database->providerCredential === null) {
            throw new RuntimeException('The database has no Upstash credential.');
        }

        return new UpstashService($database->providerCredential);
    }
}
