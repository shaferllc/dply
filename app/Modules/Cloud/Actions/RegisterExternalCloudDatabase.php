<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Actions;

use App\Models\CloudDatabase;
use App\Models\Organization;
use InvalidArgumentException;

/**
 * Registers an operator-supplied Postgres/MySQL connection as a
 * CloudDatabase (backend=external). No provider API call — the row
 * is immediately ACTIVE with an encrypted connection block so
 * AttachCloudDatabaseJob / ApplyCloudSiteExtras can inject env vars
 * into App Runner (or DO) sites.
 *
 * Use when the org hosts the DB themselves (RDS, Neon, AlloyDB, etc.)
 * and App Runner can reach the endpoint (public + SSL, or a VPC
 * connector configured outside dply).
 */
class RegisterExternalCloudDatabase
{
    private const ENGINES = [
        CloudDatabase::ENGINE_POSTGRES,
        CloudDatabase::ENGINE_MYSQL,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Organization $organization, array $payload): CloudDatabase
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || strlen($name) < 3) {
            throw new InvalidArgumentException('A database name is required (min 3 characters).');
        }

        $engine = strtolower(trim((string) ($payload['engine'] ?? '')));
        if (! in_array($engine, self::ENGINES, true)) {
            throw new InvalidArgumentException(
                'External databases support postgres or mysql (not redis).',
            );
        }

        $host = trim((string) ($payload['host'] ?? ''));
        if ($host === '' || strlen($host) > 253) {
            throw new InvalidArgumentException('A database host is required.');
        }

        $port = (int) ($payload['port'] ?? ($engine === CloudDatabase::ENGINE_MYSQL ? 3306 : 5432));
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Port must be between 1 and 65535.');
        }

        $databaseName = trim((string) ($payload['database'] ?? ''));
        if ($databaseName === '') {
            throw new InvalidArgumentException('A database name (schema) is required.');
        }

        $username = trim((string) ($payload['username'] ?? ''));
        if ($username === '') {
            throw new InvalidArgumentException('A database username is required.');
        }

        $password = (string) ($payload['password'] ?? '');
        if ($password === '') {
            throw new InvalidArgumentException('A database password is required.');
        }

        $ssl = filter_var($payload['ssl'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $existing = CloudDatabase::query()
            ->where('organization_id', $organization->id)
            ->where('name', $name)
            ->first();
        if ($existing !== null) {
            throw new InvalidArgumentException("A database named \"{$name}\" already exists in this organization.");
        }

        return CloudDatabase::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'engine' => $engine,
            'version' => trim((string) ($payload['version'] ?? '')),
            'size' => 'external',
            'region' => trim((string) ($payload['region'] ?? 'external')) ?: 'external',
            'backend' => CloudDatabase::BACKEND_EXTERNAL,
            'backend_id' => null,
            'provider_credential_id' => null,
            'status' => CloudDatabase::STATUS_ACTIVE,
            'connection' => [
                'host' => $host,
                'port' => (string) $port,
                'database' => $databaseName,
                'username' => $username,
                'password' => $password,
                'ssl' => $ssl,
                'sslmode' => $ssl ? 'require' : 'prefer',
            ],
            'meta' => [
                'source' => 'external',
                'registered_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
