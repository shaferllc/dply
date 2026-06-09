<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\RunLaravelScaffoldJob;
use App\Jobs\RunWordPressScaffoldJob;
use App\Models\Site;
use App\Services\Scaffold\PlaceholderDnsManager;
use App\Services\Scaffold\ScaffoldStep;
use Illuminate\Support\Facades\Auth;

/**
 * Drives the app-install (scaffold) pipeline surface — the steps timeline,
 * the three-attempt retry, and the one-time admin-password reveal.
 *
 * Shared by the in-wrapper flow on {@see \App\Livewire\Sites\Show} (rendered
 * inside the site workspace shell) and the legacy standalone
 * {@see \App\Livewire\Sites\ScaffoldJourney} page. Methods are scaffold-prefixed
 * so they never collide with the host component's own actions.
 */
trait InteractsWithScaffoldJourney
{
    /** Reveal-once gate for the generated admin password (Q7). */
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
        $user = Auth::user();
        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess($user)) {
            $this->addError('reveal', __('Only org owners or admins can reveal the admin password.'));

            return;
        }

        $this->scaffoldPasswordRevealed = true;
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

        return [
            'steps' => $steps,
            'isRunning' => $this->scaffoldIsRunning(),
            'isFailed' => $this->scaffoldIsFailed(),
            'isCompleted' => $this->scaffoldIsCompleted(),
            'attemptCount' => (int) ($this->site->meta['scaffold']['attempt_count'] ?? 1),
            'canRetry' => $this->scaffoldCanRetry(),
            'failedStep' => collect($steps)->firstWhere('state', ScaffoldStep::STATE_FAILED),
            'scaffoldFramework' => ucfirst((string) ($this->site->meta['scaffold']['framework'] ?? 'site')),
        ];
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
