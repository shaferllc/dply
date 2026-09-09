<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Models\CloudDatabase;
use App\Modules\Database\Backends\DatabaseBackend;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Jobs\RestoreManagedDatabaseJob;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Automatic backups on a managed cluster, and restoring one.
 *
 * Restore deliberately mirrors Vapor's semantics: it produces a *second*
 * database rather than rewinding the first. Providers offer no way to undo an
 * in-place restore, so the only safe shape is one where the operator compares
 * the two and re-attaches by hand.
 */
class ManagedDatabaseBackups
{
    public function __construct(
        private readonly DatabaseRouter $router,
    ) {}

    public function supports(CloudDatabase $database): bool
    {
        if ($database->isExternal() || blank($database->backend_id)) {
            return false;
        }

        try {
            return $this->router->backendFor($database)->supports(DatabaseBackend::CAP_BACKUPS);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Restore points on the cluster, newest first.
     *
     * A provider failure degrades to an empty list — the backups panel says
     * "none reported", which is the same thing the operator can act on.
     *
     * @return list<array{created_at: string, size_gigabytes: float}>
     */
    public function list(CloudDatabase $database): array
    {
        if (! $this->supports($database)) {
            return [];
        }

        try {
            return $this->router->backendFor($database)->backups($database);
        } catch (Throwable $e) {
            Log::warning('database.backups.list_failed', [
                'cloud_database_id' => $database->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Create a sibling database seeded from $backupCreatedAt.
     *
     * The new row copies the source's engine, version, size, region, backend
     * and credential — a restore that lands on a different plan or in a
     * different datacenter is not a restore. `meta.restored_from` records the
     * lineage so the row is not mistaken for a stray cluster later.
     */
    public function restore(CloudDatabase $source, string $name, string $backupCreatedAt): CloudDatabase
    {
        if (! $this->supports($source)) {
            throw new RuntimeException(__('This database backend cannot restore from a backup.'));
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException(__('Enter a name for the restored database.'));
        }

        if (trim($backupCreatedAt) === '') {
            throw new RuntimeException(__('Choose a backup to restore from.'));
        }

        $target = CloudDatabase::query()->create([
            'organization_id' => $source->organization_id,
            'name' => $name,
            'engine' => $source->engine,
            'version' => $source->version,
            'size' => $source->size,
            'region' => $source->region,
            'backend' => $source->backend,
            'provider_credential_id' => $source->provider_credential_id,
            'status' => CloudDatabase::STATUS_PROVISIONING,
            'connection' => [],
            'meta' => [
                'restored_from' => [
                    'cloud_database_id' => (string) $source->id,
                    'name' => (string) $source->name,
                    'backup_created_at' => $backupCreatedAt,
                    'requested_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        RestoreManagedDatabaseJob::dispatch(
            (string) $target->id,
            (string) $source->id,
            $backupCreatedAt,
        );

        return $target;
    }
}
