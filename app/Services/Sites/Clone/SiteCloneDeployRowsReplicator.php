<?php

namespace App\Services\Sites\Clone;

use App\Models\Site;

final class SiteCloneDeployRowsReplicator
{
    public static function replicate(Site $source, Site $destination): void
    {
        $source->loadMissing(['deployHooks', 'deploySteps', 'redirects']);

        foreach ($source->redirects as $redirect) {
            $row = $redirect->replicate();
            $row->site_id = $destination->id;
            $row->save();
        }

        foreach ($source->deployHooks as $hook) {
            $row = $hook->replicate();
            $row->site_id = $destination->id;
            $row->save();
        }

        foreach ($source->deploySteps as $step) {
            $row = $step->replicate();
            $row->site_id = $destination->id;
            $row->save();
        }
    }
}
