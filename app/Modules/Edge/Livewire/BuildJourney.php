<?php

declare(strict_types=1);

namespace App\Modules\Edge\Livewire;

use App\Actions\Edge\CancelStuckEdgeDeployment;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\EdgeDeployment;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Support\Sites\SiteShowViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Single self-polling Livewire component that owns the full edge build
 * journey card: status header, the 4-step list, AND the live log output
 * tucked under whichever step it belongs to (clone → cloning row,
 * pnpm/build → installing row).
 *
 * Splits the tailed build log by `[dply:step] <name>` markers emitted by
 * {@see EdgeBuildRunner} so each step shows only the
 * lines that belong to it. Stops polling automatically once the
 * deployment leaves BUILDING / PUBLISHING.
 */
class BuildJourney extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    /** Cap buffer per step so a chatty install doesn't blow Livewire payload. */
    private const BUFFER_MAX_CHARS = 64_000;

    #[Locked]
    public string $deploymentId = '';

    /** Raw log buffer, fed by tail(); split on render. */
    public string $buffer = '';

    public int $offset = 0;

    /** Flips to false once the deployment is no longer in flight. */
    public bool $polling = true;

    public function mount(string $deploymentId): void
    {
        $this->deploymentId = $deploymentId;
        $this->tail();
    }

    /**
     * Open the shared confirm-action modal before restarting. Two-step
     * flow lets us show a richer "this might race the existing job"
     * warning than the native `wire:confirm` toast can.
     */
    public function confirmRestartFrozenBuild(): void
    {
        $deployment = EdgeDeployment::query()
            ->with('site')
            ->find($this->deploymentId);

        if ($deployment === null || $deployment->site === null) {
            $this->polling = false;

            return;
        }

        Gate::authorize('update', $deployment->site);

        if (! in_array($deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true)) {
            $this->polling = false;

            return;
        }

        $details = array_values(array_filter([
            $deployment->git_branch ? [
                'label' => __('Branch'),
                'value' => (string) $deployment->git_branch,
                'mono' => true,
            ] : null,
            $deployment->git_commit ? [
                'label' => __('Commit'),
                'value' => substr((string) $deployment->git_commit, 0, 12),
                'mono' => true,
            ] : null,
            $deployment->created_at ? [
                'label' => __('Started'),
                'value' => $deployment->created_at->diffForHumans(),
            ] : null,
        ]));

        $this->openConfirmActionModal(
            'restartFrozenBuild',
            [],
            __('Restart this build?'),
            __('The in-flight deployment will be marked failed and a fresh build will be queued at the same commit. If the worker is actually still running, both builds may race — superseded ones will be cleaned up automatically.'),
            __('Restart build'),
            true,
            $details === [] ? null : $details,
        );
    }

    /**
     * Operator escape hatch — mark the in-flight deployment failed and
     * queue a fresh build. The parent component polls the deploys list
     * and will swap in a new BuildJourney card for the new deployment
     * on its next tick, so this card just stops polling and bows out.
     */
    public function restartFrozenBuild(): void
    {
        $deployment = EdgeDeployment::query()
            ->with('site')
            ->find($this->deploymentId);

        if ($deployment === null || $deployment->site === null) {
            $this->polling = false;

            return;
        }

        Gate::authorize('update', $deployment->site);

        try {
            app(CancelStuckEdgeDeployment::class)->handle($deployment->site, $deployment);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $org = $deployment->site->organization;
        if ($org !== null) {
            audit_log($org, auth()->user(), 'site.edge.deployment.cancelled', $deployment->site, null, [
                'deployment_id' => $deployment->id,
                'reason' => 'stuck',
            ]);
        }

        $this->polling = false;
        $this->toastSuccess(__('Build cancelled — fresh deploy queued.'));
    }

    public function tail(): void
    {
        $deployment = EdgeDeployment::query()
            ->with('site')
            ->find($this->deploymentId);

        if ($deployment === null) {
            $this->polling = false;

            return;
        }

        if ($deployment->site !== null) {
            Gate::authorize('view', $deployment->site);
        }

        $isInProgress = in_array($deployment->status, [
            EdgeDeployment::STATUS_BUILDING,
            EdgeDeployment::STATUS_PUBLISHING,
        ], true);

        $chunk = $deployment->readLocalBuildLogSince($this->offset, 16_000);

        if ($chunk['body'] !== '') {
            $this->buffer .= $chunk['body'];
            $this->offset = $chunk['offset'];

            if (strlen($this->buffer) > self::BUFFER_MAX_CHARS) {
                $this->buffer = "… (older lines trimmed) …\n".substr($this->buffer, -self::BUFFER_MAX_CHARS);
            }
        }

        if (! $isInProgress) {
            $this->polling = false;
        }
    }

    public function render(): View
    {
        $deployment = EdgeDeployment::query()
            ->with('site.server')
            ->find($this->deploymentId);

        if ($deployment === null) {
            return view('livewire.edge.build-journey', [
                'missing' => true,
                'journey' => null,
                'sections' => [],
                'deployment' => null,
                'site' => null,
                'server' => null,
            ]);
        }

        $journey = SiteShowViewData::edgeDeploymentJourney($deployment);
        $sections = $this->splitBufferBySteps($this->buffer);

        // Fallback: if the runner is older code (no `[dply:step]` markers)
        // OR the build died before emitting one, attribute the whole
        // buffer to the current step so the operator still sees output
        // rather than a silent empty steps list.
        if ($sections === [] && trim($this->buffer) !== '') {
            $sectionMap = [
                'queued' => 'clone',
                'building' => 'build',
                'publishing' => 'publish',
                'live' => 'publish',
                'failed' => 'build',
            ];
            $fallbackKey = $sectionMap[$journey['state']] ?? 'build';
            $sections = [$fallbackKey => trim($this->buffer)];
        }

        return view('livewire.edge.build-journey', [
            'missing' => false,
            'journey' => $journey,
            'sections' => $sections,
            'deployment' => $deployment,
            'site' => $deployment->site,
            'server' => $deployment->site?->server,
        ]);
    }

    /**
     * Split the streamed log on `[dply:step] <name>` markers. Anything
     * before the first marker is dropped (it's just the `=== dply Edge
     * build <id> ===` header line). Returns a map of step key →
     * concatenated lines.
     *
     * @return array<string, string>
     */
    private function splitBufferBySteps(string $buffer): array
    {
        if ($buffer === '') {
            return [];
        }

        $sections = [];
        $currentKey = null;
        $currentBuf = '';

        foreach (preg_split('/\r?\n/', $buffer) ?: [] as $line) {
            if (preg_match('/^\[dply:step\]\s+([a-z0-9_-]+)\s*$/i', $line, $m) === 1) {
                if ($currentKey !== null) {
                    $sections[$currentKey] = rtrim($currentBuf, "\n");
                }
                $currentKey = strtolower($m[1]);
                $currentBuf = '';

                continue;
            }
            if ($currentKey !== null) {
                $currentBuf .= $line."\n";
            }
        }

        if ($currentKey !== null) {
            $sections[$currentKey] = rtrim($currentBuf, "\n");
        }

        return $sections;
    }
}
