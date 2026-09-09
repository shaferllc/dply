<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Models\ConsoleAction;
use App\Models\SiteDeployment;
use App\Modules\Remediations\Jobs\ApplyRemediationJob;
use App\Modules\Remediations\Services\RemediationCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Shared "Fix a recognized deploy failure" behaviour for the deploy surfaces
 * (the deploy hub's Deploy panel and the deployment-detail permalink). Matches a
 * failed deployment's output against the remediations catalog and applies the
 * chosen action over SSH. Hosts must expose public `$server` and `$site` and use
 * a toast trait.
 */
trait SurfacesDeploymentRemediation
{
    // The progress banner the panel renders has a Dismiss button → provide its
    // handler, scoped to this site.
    use DismissesConsoleActionRun;

    protected function consoleActionSubject(): Model
    {
        return $this->site;
    }

    /**
     * The recognized remediation for a failed deployment, or null.
     *
     * @return array<string, mixed>|null
     */
    public function remediationForDeployment(?SiteDeployment $deployment): ?array
    {
        if ($deployment === null || $deployment->status !== SiteDeployment::STATUS_FAILED) {
            return null;
        }

        return app(RemediationCatalog::class)->match($this->deploymentFailureText($deployment));
    }

    /** Latest non-dismissed fix run for this site, for the in-page progress banner. */
    #[Computed]
    public function deploymentRemediationRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->where('kind', 'remediation_apply')
            ->whereNull('dismissed_at')
            ->orderByRaw('case when status in (?, ?) then 0 else 1 end', [
                ConsoleAction::STATUS_QUEUED,
                ConsoleAction::STATUS_RUNNING,
            ])
            ->orderByDesc('created_at')
            ->first();
    }

    /** Queue a remediation action for a failed deployment. */
    public function applyDeploymentRemediation(string $deploymentId, string $actionKey): void
    {
        Gate::authorize('update', $this->site);

        $deployment = SiteDeployment::query()->where('site_id', $this->site->id)->whereKey($deploymentId)->first();
        if ($deployment === null || $deployment->status !== SiteDeployment::STATUS_FAILED) {
            $this->toastError(__('That fix is no longer available.'));

            return;
        }

        $catalog = app(RemediationCatalog::class);
        $code = null;
        foreach ($catalog->matchAll($this->deploymentFailureText($deployment)) as $remediation) {
            if ($catalog->action((string) $remediation['code'], $actionKey) !== null) {
                $code = (string) $remediation['code'];
                break;
            }
        }

        if ($code === null) {
            $this->toastError(__('That fix is no longer available.'));

            return;
        }

        $this->queueSiteRemediationApply($code, $actionKey);
    }

    /**
     * Seed a console row, dispatch the job onto it, and watch the banner.
     * A second click while the same fix is in flight attaches to that run
     * instead of starting another job (which would fail the PHP mutex).
     */
    protected function queueSiteRemediationApply(string $code, string $actionKey, ?string $errorEventId = null): void
    {
        $existing = $this->inFlightRemediationRun();
        if ($existing !== null) {
            $this->attachToRemediationRun($existing);
            $this->toastSuccess(__('That fix is already running — output is in the console above.'));

            return;
        }

        $run = method_exists($this, 'seedQueuedConsoleAction')
            ? $this->seedQueuedConsoleAction('remediation_apply', __('Applying fix'))
            : null;

        ApplyRemediationJob::dispatch(
            (string) $this->server->id,
            (string) $this->site->id,
            $code,
            $actionKey,
            (string) (auth()->id() ?? '') ?: null,
            $errorEventId,
            $run !== null ? (string) $run->id : null,
        );

        if ($run !== null) {
            $this->attachToRemediationRun($run);
        }

        unset($this->deploymentRemediationRun);
        $this->toastSuccess(__('Applying the fix — progress shows in the console above. Re-deploy once it finishes.'));
    }

    protected function inFlightRemediationRun(): ?ConsoleAction
    {
        $run = ConsoleAction::query()
            ->forSubject($this->site)
            ->ofKind('remediation_apply')
            ->notDismissed()
            ->inFlight()
            ->orderByDesc('created_at')
            ->first();

        if ($run === null || $run->isStale()) {
            return null;
        }

        return $run;
    }

    protected function attachToRemediationRun(ConsoleAction $run): void
    {
        if (method_exists($this, 'watchConsoleAction')) {
            $this->watchConsoleAction(
                $run,
                __('Fix applied. Re-deploy to continue.'),
                __('The fix did not finish — see the console output.'),
            );
            $this->dispatch('dply-console-action-focus');
        }

        unset($this->deploymentRemediationRun);
    }

    /** Full failure output to match against — the overall log plus any step outputs. */
    private function deploymentFailureText(SiteDeployment $deployment): string
    {
        $parts = [(string) $deployment->log_output];

        $phaseResults = is_array($deployment->phase_results ?? null) ? $deployment->phase_results : [];
        array_walk_recursive($phaseResults, function ($value) use (&$parts): void {
            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        });

        return implode("\n", $parts);
    }
}
