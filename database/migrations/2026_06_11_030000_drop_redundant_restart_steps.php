<?php

use App\Models\SiteDeployStep;
use Illuminate\Database\Migrations\Migration;

/**
 * Drop explicit queue:restart / horizon:terminate pipeline steps. They are
 * redundant with dply's managed restart, which already reloads Horizon + queue
 * workers post-cutover — guarded on the package + command existing — so removing
 * the steps doesn't change behaviour or risk breaking an app that lacks them.
 *
 * EXCEPTION: sites that opted out of the managed restart
 * (meta.deploy.skip_managed_restart = true) rely on their explicit steps, so
 * theirs are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        SiteDeployStep::query()
            ->whereIn('step_type', [
                SiteDeployStep::TYPE_ARTISAN_QUEUE_RESTART,
                SiteDeployStep::TYPE_ARTISAN_HORIZON_TERMINATE,
            ])
            ->with('site:id,meta')
            ->get()
            ->each(function (SiteDeployStep $step): void {
                $skipsManagedRestart = (bool) data_get($step->site?->meta, 'deploy.skip_managed_restart', false);
                if (! $skipsManagedRestart) {
                    $step->delete();
                }
            });
    }

    public function down(): void
    {
        // Not reversible — the deleted steps aren't snapshotted. dply's managed
        // restart provides the equivalent behaviour, so nothing to restore.
    }
};
