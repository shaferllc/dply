<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Scaffold\ScaffoldComposerPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Worker for the generic, recipe-driven Composer scaffold pipeline
 * (Statamic, Symfony, Craft, Drupal, …). The recipe lives at
 * meta.scaffold.recipe, written by Sites/ChooseApp. Mirrors
 * {@see RunLaravelScaffoldJob} for the dedicated installers.
 */
class RunComposerScaffoldJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** composer create-project can run long on first-cache machines. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public string $siteId) {}

    public function handle(ScaffoldComposerPipeline $pipeline): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }
        if ($site->status !== Site::STATUS_SCAFFOLDING) {
            // Idempotence — already scaffolded or moved on.
            return;
        }

        $pipeline->run($site);
    }
}
