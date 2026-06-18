<?php

declare(strict_types=1);

namespace App\Modules\Snapshots;

use Illuminate\Support\ServiceProvider;

/** Snapshots module command wiring — the site-snapshot take/restore CLI. */
class SnapshotsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\SnapshotTakeCommand::class,
                Console\SnapshotRestoreCommand::class,
            ]);
        }
    }
}
