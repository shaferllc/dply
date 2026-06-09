<?php

namespace App\Services\Sites\Clone;

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use App\Services\Deploy\SiteDeployPipelineManager;

final class SiteCloneDeployRowsReplicator
{
    public static function replicate(Site $source, Site $destination): void
    {
        $source->loadMissing(['deployPipelines.steps', 'deployPipelines.hooks', 'redirects']);

        foreach ($source->redirects as $redirect) {
            $row = $redirect->replicate();
            $row->site_id = $destination->id;
            $row->save();
        }

        $pipelineMap = [];
        foreach ($source->deployPipelines as $pipeline) {
            $slug = self::uniqueSlug($destination, $pipeline->slug);
            $copy = SiteDeployPipeline::query()->create([
                'site_id' => $destination->id,
                'name' => $pipeline->name,
                'slug' => $slug,
                'description' => $pipeline->description,
                'clone_script' => $pipeline->clone_script,
                'activate_script' => $pipeline->activate_script,
                'is_default' => $pipeline->is_default,
                'sort_order' => $pipeline->sort_order,
            ]);
            $pipelineMap[(string) $pipeline->id] = $copy;

            $stepMap = [];
            foreach ($pipeline->steps as $step) {
                $row = $step->replicate();
                $row->site_id = $destination->id;
                $row->pipeline_id = $copy->id;
                $row->save();
                $stepMap[(string) $step->id] = (string) $row->id;
            }
            foreach ($pipeline->hooks as $hook) {
                $row = $hook->replicate();
                $row->site_id = $destination->id;
                $row->pipeline_id = $copy->id;
                $row->anchor_step_id = $hook->anchor_step_id
                    ? ($stepMap[(string) $hook->anchor_step_id] ?? null)
                    : null;
                $row->save();
            }
        }

        $activeId = $source->active_deploy_pipeline_id;
        if ($activeId && isset($pipelineMap[(string) $activeId])) {
            $destination->forceFill([
                'active_deploy_pipeline_id' => $pipelineMap[(string) $activeId]->id,
            ])->save();
        } else {
            app(SiteDeployPipelineManager::class)->ensureDefaultPipeline($destination);
        }
    }

    private static function uniqueSlug(Site $site, string $base): string
    {
        $slug = $base !== '' ? $base : 'pipeline';
        $candidate = $slug;
        $n = 2;
        while (SiteDeployPipeline::query()->where('site_id', $site->id)->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$n;
            $n++;
        }

        return $candidate;
    }
}
