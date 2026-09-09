<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Models\SiteDeployPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Projects a primary site's deploy configuration onto its worker replicas.
 *
 * A replica is a copy of the primary taken at scale-up, and its pipeline was
 * seeded from generic runtime defaults rather than the primary's — so a worker
 * ran a materially different deploy from the site it is supposed to be a replica
 * of, and drifted further with every edit to the primary. Deploy configuration
 * is the primary's to own; a replica should never diverge from it.
 *
 * Replica-owned concerns are deliberately NOT touched: env (see
 * SyncWorkerPoolEnvJob, which preserves queue/HORIZON/role keys), the scheduler
 * flag, hostnames, and provisioning state.
 */
class WorkerReplicaDeployConfigSync
{
    /** Columns that decide WHAT is deployed and HOW — all owned by the primary. */
    private const INHERITED_COLUMNS = [
        'git_repository_url',
        'git_branch',
        'deploy_strategy',
        'releases_to_keep',
        'build_command',
        'start_command',
        'runtime',
        'runtime_version',
        'restart_supervisor_programs_after_deploy',
    ];

    /**
     * Sync every replica of this primary. Returns the number updated.
     */
    public function syncReplicasOf(Site $primary): int
    {
        $replicas = Site::query()
            ->where('meta->replicated_from_site_id', (string) $primary->id)
            ->get();

        $synced = 0;
        foreach ($replicas as $replica) {
            if ($this->sync($primary, $replica)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function sync(Site $primary, Site $replica): bool
    {
        $updates = [];
        foreach (self::INHERITED_COLUMNS as $column) {
            if ($column === 'deploy_strategy' && $replica->isDisablingAtomicLayout()) {
                continue;
            }

            $value = $primary->getAttribute($column);
            if ($replica->getAttribute($column) !== $value) {
                $updates[$column] = $value;
            }
        }

        // git_ref_kind lives in meta and decides whether git_branch is treated as
        // a branch or a pinned commit. A pinned replica keeps its own pin.
        $replicaMeta = is_array($replica->meta) ? $replica->meta : [];
        if (($replicaMeta['pinned_release'] ?? '') === '') {
            $primaryMeta = is_array($primary->meta) ? $primary->meta : [];
            $refKind = $primaryMeta['git_ref_kind'] ?? null;
            if (($replicaMeta['git_ref_kind'] ?? null) !== $refKind) {
                $replicaMeta['git_ref_kind'] = $refKind;
                $updates['meta'] = $replicaMeta;
            }
        }

        if ($updates !== []) {
            $replica->forceFill($updates)->save();
        }

        $pipelineChanged = $this->projectPipeline($primary, $replica);

        return $updates !== [] || $pipelineChanged;
    }

    /**
     * Replace the replica's pipeline with a copy of the primary's active one.
     *
     * Rebuilt rather than diffed: steps and hooks are ordered and cross-
     * referenced (a hook can anchor to a step), so a partial update risks a
     * pipeline that is neither the primary's nor a coherent one of its own.
     */
    private function projectPipeline(Site $primary, Site $replica): bool
    {
        $source = $primary->activeDeployPipeline
            ?? $primary->deployPipelines()->where('is_default', true)->first()
            ?? $primary->deployPipelines()->first();

        if (! $source instanceof SiteDeployPipeline) {
            return false;
        }

        $source->loadMissing(['steps', 'hooks']);

        return (bool) DB::transaction(function () use ($source, $replica): bool {
            foreach ($replica->deployPipelines()->get() as $existing) {
                $existing->hooks()->delete();
                $existing->steps()->delete();
                $existing->delete();
            }

            $pipeline = $replica->deployPipelines()->create([
                'name' => $source->name,
                'slug' => Str::slug((string) $source->slug) ?: 'pipeline',
                'description' => $source->description,
                'is_default' => true,
                'sort_order' => 1,
                'clone_script' => $source->clone_script,
                'activate_script' => $source->activate_script,
            ]);

            $stepMap = [];
            foreach ($source->steps()->orderBy('sort_order')->get() as $step) {
                $copy = $pipeline->steps()->create([
                    'site_id' => $replica->id,
                    'sort_order' => $step->sort_order,
                    'step_type' => $step->step_type,
                    'phase' => $step->phase,
                    'custom_command' => $step->custom_command,
                    'timeout_seconds' => $step->timeout_seconds,
                ]);
                $stepMap[(string) $step->id] = (string) $copy->id;
            }

            foreach ($source->hooks()->orderBy('sort_order')->get() as $hook) {
                $pipeline->hooks()->create([
                    'site_id' => $replica->id,
                    'sort_order' => $hook->sort_order,
                    'phase' => $hook->phase,
                    'hook_kind' => $hook->hook_kind,
                    'anchor' => $hook->anchor,
                    'anchor_step_id' => $hook->anchor_step_id
                        ? ($stepMap[(string) $hook->anchor_step_id] ?? null)
                        : null,
                    'label' => $hook->label,
                    'script' => $hook->script,
                    'webhook_url' => $hook->webhook_url,
                    'notification_channel_id' => $hook->notification_channel_id,
                    'notification_event' => $hook->notification_event,
                    'timeout_seconds' => $hook->timeout_seconds,
                ]);
            }

            $replica->forceFill(['active_deploy_pipeline_id' => $pipeline->id])->save();

            return true;
        });
    }
}
