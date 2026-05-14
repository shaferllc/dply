<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;

final class DumpDatabaseHandler extends PendingPhase3bHandler
{
    public static function key(): string
    {
        return ImportMigrationStep::KEY_DUMP_DB;
    }
}
