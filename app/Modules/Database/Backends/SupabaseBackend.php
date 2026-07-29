<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Modules\Database\Services\SupabaseService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Supabase (serverless Postgres) backend. BYO account, region-agnostic.
 *
 * We supply the DB password at create time and derive the host from the
 * project ref (db.<ref>.supabase.co), so the full connection is known
 * immediately and stored encrypted; poll() just waits for the project to
 * report ACTIVE_HEALTHY before reporting online.
 */
class SupabaseBackend implements DatabaseBackend
{
    public function key(): string
    {
        return CloudDatabase::BACKEND_SUPABASE;
    }

    public function supportedEngines(): array
    {
        return [CloudDatabase::ENGINE_POSTGRES];
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
        $dbPass = Str::password(24);
        $ref = $this->service($database)->createProject(
            $database->name,
            $dbPass,
            $database->region !== '' ? $database->region : 'us-east-1',
        );

        $database->forceFill([
            'backend_id' => $ref,
            'connection' => [
                'host' => 'db.'.$ref.'.supabase.co',
                'port' => '5432',
                'username' => 'postgres',
                'password' => $dbPass,
                'database' => 'postgres',
                'ssl' => true,
            ],
        ])->save();
    }

    public function poll(CloudDatabase $database): array
    {
        if (! is_string($database->backend_id) || $database->backend_id === '') {
            $this->provision($database);
        }

        $status = $this->service($database)->projectStatus((string) $database->backend_id);
        $connection = $database->getAttribute('connection');
        $connection = is_array($connection) ? $connection : [];
        $ready = $status === 'ACTIVE_HEALTHY' && (string) ($connection['host'] ?? '') !== '';

        return ['status' => $ready ? 'online' : 'creating', 'connection' => $connection];
    }

    public function lockNetworkTo(CloudDatabase $database, Server $server): void
    {
        // Supabase Postgres is reachable over TLS; network restriction is
        // configured in the Supabase dashboard, not part of the BYO v1 flow.
    }

    private function service(CloudDatabase $database): SupabaseService
    {
        $database->loadMissing('providerCredential');
        if ($database->providerCredential === null) {
            throw new RuntimeException('The database has no Supabase credential.');
        }

        return new SupabaseService($database->providerCredential);
    }
}
