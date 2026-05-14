<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;

final class RecreateSchedulerHandler extends PendingPhase3bHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_RECREATE_SCHEDULER;
    }
}
