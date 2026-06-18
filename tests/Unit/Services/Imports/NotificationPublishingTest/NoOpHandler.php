<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\NotificationPublishingTest;

use App\Models\ImportMigrationStep;
use App\Modules\Imports\Services\StepHandler;

final class NoOpHandler implements StepHandler
{
    public static function key(): string
    {
        return 'freeze_snapshot';
    }

    public function execute(ImportMigrationStep $step): void {}
}
