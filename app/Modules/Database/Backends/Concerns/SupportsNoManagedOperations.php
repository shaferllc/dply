<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends\Concerns;

use App\Models\CloudDatabase;
use RuntimeException;

/**
 * Safe defaults for a backend that only knows how to create, poll and delete.
 *
 * The day-two operations — users, metrics, backups, restore — each need a
 * provider API dply has not wrapped for these vendors. Declaring that here,
 * once, is what lets the detail page render an honest "not available on this
 * backend" panel instead of an empty one that looks like a failed load.
 *
 * Deliberately orthogonal to {@see CannotResizeManagedDatabase}: a backend can
 * gain resize without gaining backups, and vice versa. A vendor that grows one
 * of these overrides just that method.
 */
trait SupportsNoManagedOperations
{
    public function supports(string $capability): bool
    {
        return false;
    }

    public function metricCatalog(CloudDatabase $database): array
    {
        return [];
    }

    public function metric(CloudDatabase $database, string $metric, int $start, int $end): array
    {
        return [];
    }

    public function backups(CloudDatabase $database): array
    {
        return [];
    }

    public function provisionFromBackup(CloudDatabase $target, CloudDatabase $source, string $backupCreatedAt): void
    {
        throw new RuntimeException(__('This database backend cannot restore from a backup.'));
    }
}
