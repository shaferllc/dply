<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ResetSiteToBlankJob;
use App\Livewire\Sites\ScaffoldJourney;
use App\Livewire\Sites\Show;
use App\Models\Site;
use App\Modules\Scaffold\Jobs\RunLaravelScaffoldJob;
use App\Modules\Scaffold\Jobs\RunWordPressScaffoldJob;
use App\Modules\Scaffold\Services\PlaceholderDnsManager;
use App\Modules\Scaffold\Services\ScaffoldStep;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Drives the app-install (scaffold) pipeline surface — the steps timeline,
 * the three-attempt retry, and the one-time admin-password reveal.
 *
 * Shared by the in-wrapper flow on {@see Show} (rendered
 * inside the site workspace shell) and the legacy standalone
 * {@see ScaffoldJourney} page. Methods are scaffold-prefixed
 * so they never collide with the host component's own actions.
 */
trait InteractsWithScaffoldJourney
{
    /** Reveal-once gate for the generated admin password (Q7). Locked so viewers cannot `$wire.set` it. */
    #[Locked]
    public bool $scaffoldPasswordRevealed = false;

    /** Lightweight poll target — just pulls fresh scaffold meta. */
    public function pollScaffoldStatus(): void
    {
        $this->site->refresh();
    }

    public function revealScaffoldPassword(): void
    {
        // PR 17 (re-auth-required reveal) hardens this; v1 just gates
        // by org admin so non-admins can't read the secret from the UI.
        if (! $this->userCanRevealScaffoldPassword()) {
            $this->addError('reveal', __('Only org owners or admins can reveal the admin password.'));

            return;
        }

        $this->scaffoldPasswordRevealed = true;
    }

    /**
     * Abandon a failed install and go back to the app picker.
     *
     * Distinct from {@see retryScaffold()}, which re-runs the SAME installer:
     * this clears the scaffold entirely so a different app can be chosen. It is
     * the way out when retrying will not help (a broken install, or the wrong
     * app picked) — previously the only offer past the three-attempt cap was a
     * "Delete site and start fresh" link that pointed back at the same page and
     * did nothing at all.
     *
     * Keeps the site shell (server, domains, testing URL, certificates) exactly
     * as disconnectAndStartOver() does; only the application is removed.
     */
    public function resetScaffoldAndChooseAgain(): void
    {
        $this->authorize('update', $this->site);

        $site = $this->site;
        $meta = is_array($site->meta) ? $site->meta : [];

        unset($meta['scaffold']);

        // The "skipped" sentinel is what makes Site::canRechooseApp() true, so
        // the picker reopens instead of the workspace bouncing straight back.
        $meta['choose_app'] = [
            'skipped' => true,
            'reset_at' => now()->toIso8601String(),
            'reset_by_user_id' => Auth::id(),
        ];

        $site->forceFill([
            // Clearing the status matters as much as clearing the meta:
            // lacksInstalledApp() short-circuits on STATUS_SCAFFOLD_FAILED, so
            // leaving it would keep canRechooseApp() false and the picker shut
            // even with the scaffold gone. AWAITING_APP is precisely this state.
            'status' => Site::STATUS_AWAITING_APP,
            'git_repository_url' => '',
            'git_branch' => 'main',
            'last_deploy_at' => null,
            'meta' => $meta,
        ])->save();

        // Theme/plugin repos belonged to the app being abandoned.
        $site->gitSources()->delete();

        // Wipes the half-written install — a failed WordPress scaffold can
        // leave a broken wp-config.php behind, which makes every later wp-cli
        // call fail — and restores the splash page.
        ResetSiteToBlankJob::dispatch((string) $site->id);

        $this->redirect(route('sites.choose-app', [
            'server' => $site->server_id,
            'site' => $site->id,
        ]), navigate: true);
    }

    /**
     * Reset-and-retry per Q9: releases the prior placeholder, clears the
     * recorded steps + generated password, bumps the attempt counter, and
     * re-dispatches the framework's pipeline from step 1.
     */
    public function retryScaffold(PlaceholderDnsManager $placeholderDns): void
    {
        if (! $this->scaffoldCanRetry()) {
            return;
        }

        $framework = $this->site->meta['scaffold']['framework'] ?? null;
        if (! in_array($framework, ['laravel', 'wordpress'], true)) {
            return;
        }

        // Release the prior placeholder before clearing meta — the
        // SiteDomain row gets dropped by hostname so a hash-suffixed
        // re-assignment doesn't trip the unique constraint, and the
        // DNS A record (if any) is cleaned up provider-side. release()
        // is idempotent so this is safe even when no prior assignment
        // existed (e.g. a scaffold that failed before placeholder_dns).
        $priorHostname = $this->site->meta['scaffold']['placeholder_dns']['hostname'] ?? null;
        $placeholderDns->release($this->site);
        if (is_string($priorHostname) && $priorHostname !== '') {
            $this->site->domains()->where('hostname', $priorHostname)->delete();
            $this->site->refresh();
        }

        $meta = $this->site->meta;
        $meta['scaffold']['attempt_count'] = ((int) ($meta['scaffold']['attempt_count'] ?? 1)) + 1;
        $meta['scaffold']['steps'] = []; // Pipeline re-initialises on run.
        unset($meta['scaffold']['admin_password']); // Generated fresh.
        $this->site->meta = $meta;
        $this->site->status = Site::STATUS_SCAFFOLDING;
        $this->site->save();

        if ($framework === 'laravel') {
            RunLaravelScaffoldJob::dispatch($this->site->id);
        } else {
            RunWordPressScaffoldJob::dispatch($this->site->id);
        }
    }

    /**
     * View payload for the scaffold-install partial. Both host components merge
     * this into their render() so the shared partial sees identical variables.
     *
     * @return array<string, mixed>
     */
    public function scaffoldJourneyData(): array
    {
        $steps = $this->scaffoldSteps();
        $canReveal = $this->userCanRevealScaffoldPassword();

        // Viewers must never keep a true reveal flag or receive plaintext,
        // even if the locked property was somehow flipped server-side.
        if ($this->scaffoldPasswordRevealed && ! $canReveal) {
            $this->scaffoldPasswordRevealed = false;
        }

        return [
            'steps' => $steps,
            'isRunning' => $this->scaffoldIsRunning(),
            'isFailed' => $this->scaffoldIsFailed(),
            'isCompleted' => $this->scaffoldIsCompleted(),
            'attemptCount' => (int) ($this->site->meta['scaffold']['attempt_count'] ?? 1),
            'canRetry' => $this->scaffoldCanRetry(),
            'failedStep' => collect($steps)->firstWhere('state', ScaffoldStep::STATE_FAILED),
            'scaffoldFramework' => ucfirst((string) ($this->site->meta['scaffold']['framework'] ?? 'site')),
            'revealedScaffoldAdminPassword' => $this->revealedScaffoldAdminPassword($canReveal),
        ];
    }

    private function userCanRevealScaffoldPassword(): bool
    {
        $user = Auth::user();
        $org = $this->site->organization;

        return $user !== null && $org !== null && $org->hasAdminAccess($user);
    }

    /**
     * Decrypt only for an org admin who already passed the reveal action.
     * Plaintext stays in view data for this render — never a public Livewire property.
     */
    private function revealedScaffoldAdminPassword(bool $canReveal): ?string
    {
        if (! $this->scaffoldPasswordRevealed || ! $canReveal) {
            return null;
        }

        $encrypted = $this->site->meta['scaffold']['admin_password'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        return decrypt($encrypted);
    }

    protected function siteIsScaffolded(): bool
    {
        return is_array($this->site->meta['scaffold'] ?? null);
    }

    private function scaffoldIsRunning(): bool
    {
        return $this->site->status === Site::STATUS_SCAFFOLDING;
    }

    private function scaffoldIsFailed(): bool
    {
        return $this->site->status === Site::STATUS_SCAFFOLD_FAILED;
    }

    private function scaffoldIsCompleted(): bool
    {
        return $this->site->status === Site::STATUS_PENDING
            && collect($this->scaffoldSteps())->every(fn ($s) => ($s['state'] ?? null) === ScaffoldStep::STATE_COMPLETED);
    }

    /**
     * Q9: three-attempt cap. After three failed attempts the view swaps the
     * retry button for "Delete site and start fresh".
     */
    private function scaffoldCanRetry(): bool
    {
        return $this->scaffoldIsFailed()
            && (int) ($this->site->meta['scaffold']['attempt_count'] ?? 1) < 3;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scaffoldSteps(): array
    {
        return $this->site->meta['scaffold']['steps'] ?? [];
    }
}
