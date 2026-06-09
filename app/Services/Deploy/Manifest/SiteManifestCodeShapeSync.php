<?php

declare(strict_types=1);

namespace App\Services\Deploy\Manifest;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\SiteProcess;
use App\Services\Deploy\SiteDeployPipelineManager;

/**
 * Reconciles the code-shape half of a repo `dply.yaml` (build / release /
 * processes) into the database so the deploy pipeline executes the just-pushed
 * commands on THIS deploy — closing the loop that previously froze these fields
 * at site-creation time.
 *
 * Authoritative-for-declared-fields model:
 *   - A category the manifest DECLARES (non-empty build/release/processes) is
 *     fully owned: its prior manifest-managed rows are deleted and recreated
 *     from the file. User-authored (non-managed) rows in that category are left
 *     intact so hand-added steps survive.
 *   - A category the manifest does NOT declare clears only its leftover
 *     manifest-managed rows (so deleting a key from the file removes its rows)
 *     and otherwise falls back to the dashboard.
 *
 * runtime/version are NOT applied here — they require an infra reconcile. This
 * service only DETECTS a change and reports it so the caller can apply it as an
 * explicit, logged, abortable step.
 */
final class SiteManifestCodeShapeSync
{
    public function __construct(
        private SiteDeployPipelineManager $pipelines,
    ) {}

    /**
     * @return array{build: int, release: int, processes: int, runtime_change: ?array{field: string, from: ?string, to: ?string}}
     */
    public function reconcile(Site $site, DplyManifest $manifest): array
    {
        $pipeline = $this->pipelines->ensureDefaultPipeline($site);

        return [
            'build' => $this->reconcilePhase($site, (string) $pipeline->id, SiteDeployStep::PHASE_BUILD, $manifest->build),
            'release' => $this->reconcilePhase($site, (string) $pipeline->id, SiteDeployStep::PHASE_RELEASE, $manifest->release),
            'processes' => $this->reconcileProcesses($site, $manifest->processes),
            'runtime_change' => $this->detectRuntimeChange($site, $manifest),
        ];
    }

    /**
     * @param  list<string>  $commands
     */
    private function reconcilePhase(Site $site, string $pipelineId, string $phase, array $commands): int
    {
        // Clear prior manifest-managed rows for this phase (so a removed key
        // also removes its rows); user-authored rows are never touched.
        SiteDeployStep::query()
            ->where('site_id', $site->id)
            ->where('phase', $phase)
            ->where('managed_by_manifest', true)
            ->delete();

        if ($commands === []) {
            return 0;
        }

        // Manifest-managed steps sort BEFORE user steps in the phase so the
        // declared build/release order is honored, then any hand-added steps run.
        $order = -count($commands);
        $count = 0;
        foreach ($commands as $command) {
            SiteDeployStep::query()->create([
                'site_id' => $site->id,
                'pipeline_id' => $pipelineId,
                'phase' => $phase,
                'step_type' => SiteDeployStep::TYPE_CUSTOM,
                'custom_command' => $command,
                'sort_order' => $order++,
                'managed_by_manifest' => true,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, DplyManifestProcess>  $processes
     */
    private function reconcileProcesses(Site $site, array $processes): int
    {
        SiteProcess::query()
            ->where('site_id', $site->id)
            ->where('managed_by_manifest', true)
            ->delete();

        if ($processes === []) {
            return 0;
        }

        $known = [SiteProcess::TYPE_WEB, SiteProcess::TYPE_WORKER, SiteProcess::TYPE_SCHEDULER];
        $count = 0;
        foreach ($processes as $name => $process) {
            $type = in_array($name, $known, true) ? $name : SiteProcess::TYPE_CUSTOM;
            SiteProcess::query()->create([
                'site_id' => $site->id,
                'type' => $type,
                'name' => $name,
                'command' => $process->command,
                'scale' => $process->scale,
                'is_active' => true,
                'managed_by_manifest' => true,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @return array{field: string, from: ?string, to: ?string}|null
     */
    private function detectRuntimeChange(Site $site, DplyManifest $manifest): ?array
    {
        // version is the infra-affecting one (pool/runtime re-provision). Report
        // it when the manifest pins a version different from the site's current.
        if ($manifest->version !== null) {
            $current = $site->runtime_version !== null ? (string) $site->runtime_version : null;
            if ($current !== $manifest->version) {
                return ['field' => 'version', 'from' => $current, 'to' => $manifest->version];
            }
        }

        if ($manifest->runtime !== null) {
            $current = $site->runtime !== null ? (string) $site->runtime : null;
            if ($current !== null && $current !== $manifest->runtime) {
                return ['field' => 'runtime', 'from' => $current, 'to' => $manifest->runtime];
            }
        }

        return null;
    }
}
