<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ApplyRemediationJob;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Models\ConsoleAction;
use App\Models\SiteDeployment;
use App\Services\Remediations\RemediationCatalog;
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
            ->orderByDesc('created_at')
            ->first();
    }

    /** Queue a remediation action for a failed deployment. */
    public function applyDeploymentRemediation(string $deploymentId, string $actionKey): void
    {
        Gate::authorize('update', $this->site);

        $deployment = SiteDeployment::query()->where('site_id', $this->site->id)->whereKey($deploymentId)->first();
        $remediation = $this->remediationForDeployment($deployment);
        $catalog = app(RemediationCatalog::class);
        if ($remediation === null || $catalog->action((string) $remediation['code'], $actionKey) === null) {
            $this->toastError(__('That fix is no longer available.'));

            return;
        }

        ApplyRemediationJob::dispatch(
            (string) $this->server->id,
            (string) $this->site->id,
            (string) $remediation['code'],
            $actionKey,
            (string) (auth()->id() ?? '') ?: null,
        );

        unset($this->deploymentRemediationRun);
        $this->toastSuccess(__('Applying the fix — progress shows below. Re-deploy once it finishes.'));
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
