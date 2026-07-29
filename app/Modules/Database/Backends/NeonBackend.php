<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Modules\Database\Services\NeonService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Neon (serverless Postgres) backend — the first BYO-vendor implementation.
 *
 * Unlike the co-located IaaS backends (DO / Vultr), Neon is region-agnostic
 * and lives on the customer's own Neon account: there's no server to co-locate
 * with ({@see regionForServer()} returns null) and no IP lockdown to apply
 * ({@see lockNetworkTo()} is a no-op). It's offered when a server has no
 * same-provider managed DB, or as a deliberate choice.
 */
class NeonBackend implements DatabaseBackend
{
    public function key(): string
    {
        return CloudDatabase::BACKEND_NEON;
    }

    public function supportedEngines(): array
    {
        return [CloudDatabase::ENGINE_POSTGRES];
    }

    /** Not co-located — Neon picks from its own regions, set in the modal. */
    public function regionForServer(Server $server): ?string
    {
        return null;
    }

    /** Usage-based pricing; no flat per-tier figure to show. */
    public function estimatedMonthlyCost(string $size): ?int
    {
        return null;
    }

    public function provision(CloudDatabase $database): void
    {
        $pgVersion = (int) ($database->version !== '' ? $database->version : 16);
        if ($pgVersion < 14) {
            $pgVersion = 16;
        }

        $result = $this->service($database)->createProject(
            $this->projectName($database),
            $database->region !== '' ? $database->region : 'aws-us-east-1',
            $pgVersion,
        );

        $database->forceFill([
            'backend_id' => (string) $result['id'],
            'connection' => is_array($result['connection']) ? $result['connection'] : [],
        ])->save();
    }

    public function poll(CloudDatabase $database): array
    {
        if (! is_string($database->backend_id) || $database->backend_id === '') {
            $this->provision($database);
        }

        $pending = $this->service($database)->hasPendingOperations((string) $database->backend_id);

        // The connection block was captured at create; read it back.
        $connection = $database->getAttribute('connection');
        $connection = is_array($connection) ? $connection : [];
        $ready = ! $pending && (string) ($connection['host'] ?? '') !== '';

        return [
            'status' => $ready ? 'online' : 'creating',
            'connection' => $connection,
        ];
    }

    public function lockNetworkTo(CloudDatabase $database, Server $server): void
    {
        // Neon IP allow-listing is account/project-level and not part of the
        // BYO v1 flow — connections are over TLS. No-op.
    }

    private function service(CloudDatabase $database): NeonService
    {
        $database->loadMissing('providerCredential');
        $credential = $database->providerCredential;
        if ($credential === null) {
            throw new RuntimeException('The database has no Neon credential.');
        }

        return new NeonService($credential);
    }

    private function projectName(CloudDatabase $database): string
    {
        $slug = Str::slug($database->name) ?: 'db';

        return 'dply-'.$slug.'-'.Str::lower(Str::random(6));
    }
}
