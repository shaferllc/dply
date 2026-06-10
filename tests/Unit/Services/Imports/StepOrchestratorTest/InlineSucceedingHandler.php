<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\StepOrchestratorTest;

use App\Models\ImportMigrationStep;
use App\Services\Imports\StepHandler;

final class InlineSucceedingHandler implements StepHandler
{
    public static function key(): string
    {
        return 'freeze_snapshot';
    }

    public function execute(ImportMigrationStep $step): void {}
}
