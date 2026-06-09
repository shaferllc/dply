<?php

declare(strict_types=1);

namespace App\Services\Deploy\Manifest;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\SiteProcess;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\SshConnection;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

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
        private DplyManifestParser $parser,
    ) {}

    /**
     * Pre-build hook: fetch the deployed `dply.*` from the freshly-cloned
     * release dir and reconcile code-shape so the just-pushed build/release/
     * processes execute on THIS deploy. Returns a human-readable log line for
     * the deploy timeline. Gated by `global.byo_repo_config`; a no-op (empty
     * log) when off, when there's no manifest, or when it can't be parsed.
     *
     * runtime/version changes are surfaced in the log but NOT auto-applied — a
     * version bump re-provisions the FPM pool, so it's left for an explicit
     * Settings → Runtime action (guarded).
     */
    public function applyFromRemote(Site $site, SshConnection $ssh, string $remoteDir): string
    {
        if (! Feature::active('global.byo_repo_config')) {
            return '';
        }

        $base = rtrim($remoteDir, '/');
        $found = null;
        foreach (DplyManifestParser::FILE_NAMES as $name) {
            $content = trim($ssh->exec(
                sprintf('cat %s/%s 2>/dev/null', escapeshellarg($base), escapeshellarg($name)),
                30,
            ));
            if ($content !== '') {
                $found = ['name' => $name, 'content' => $content];
                break;
            }
        }

        if ($found === null) {
            return '';
        }

        try {
            $manifest = $this->parser->parseRaw($found['content'], $found['name']);
        } catch (\Throwable $e) {
            Log::warning('dply manifest code-shape parse failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return sprintf("[dply] %s present but could not be parsed: %s\n", $found['name'], $e->getMessage());
        }

        if (! $manifest->hasCodeShape()) {
            return '';
        }

        $result = $this->reconcile($site, $manifest);

        $log = sprintf(
            "[dply] %s applied — build:%d release:%d processes:%d managed step(s)/process(es).\n",
            $found['name'],
            $result['build'],
            $result['release'],
            $result['processes'],
        );

        if ($result['runtime_change'] !== null) {
            $rc = $result['runtime_change'];
            $log .= sprintf(
                "[dply] NOTE: %s change %s → %s declared in %s is NOT auto-applied (re-provisions the runtime). Apply it in Settings → Runtime.\n",
                $rc['field'],
                $rc['from'] ?? '(unset)',
                $rc['to'] ?? '(unset)',
                $found['name'],
            );
        }

        return $log;
    }

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
