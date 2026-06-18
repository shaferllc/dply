<?php

declare(strict_types=1);

namespace App\Livewire\Cloud\Concerns;

use App\Modules\SourceControl\Services\DefaultBranchResolver;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCloudRepository
{


    public function updatedRepoSource(string $value): void
    {
        // Switching back to manual entry clears the dropdown selection
        // so the repo / branch fields don't carry over silently.
        if ($value === 'manual') {
            $this->repository_selection = '';
        }
    }

    public function updatedSourceControlAccountId(string $value): void
    {
        $this->source_control_account_id = $value;
        $this->repository_selection = '';
        $this->loadRepositoriesForSelectedAccount();
    }

    public function updatedRepositorySelection(string $value): void
    {
        if ($value === '') {
            return;
        }

        $match = collect($this->availableRepositories)->firstWhere('url', $value);
        if (! is_array($match)) {
            return;
        }

        $cloneUrl = (string) $match['url'];
        $this->repo = $this->normalizeRepo($cloneUrl);

        // Probe the actual remote — the listing's `default_branch` can be
        // missing or stale, and a wrong branch makes the runtime-detection
        // clone fail outright (e.g., master/12.x repos getting branch=main).
        $live = $this->resolveDefaultBranchForCurrentSelection($cloneUrl);
        $this->branch = $live
            ?? (is_string($match['branch'] ?? null) && $match['branch'] !== '' ? (string) $match['branch'] : 'main');

        // Picking a repo from a connected account is a deliberate choice —
        // detect immediately so the user sees the runtime preview without a
        // separate click. Manual entry uses the explicit Detect button.
        $this->detectFromRepository();
    }

    /**
     * Manual-entry counterpart to {@see updatedRepositorySelection}. Fires
     * on blur (via `wire:model.blur` in the blade) so a pasted URL gets
     * its real default branch — same reason as above, just no listing to
     * cross-reference. Detection still requires the explicit Detect button.
     */
    public function updatedRepo(): void
    {
        if ($this->mode !== 'source') {
            return;
        }
        $cloneUrl = $this->normalizeToCloneUrl($this->repo);
        if ($cloneUrl === '') {
            return;
        }
        $live = $this->resolveDefaultBranchForCurrentSelection($cloneUrl);
        if (is_string($live) && $live !== '') {
            $this->branch = $live;
        }
    }

    private function resolveDefaultBranchForCurrentSelection(string $cloneUrl): ?string
    {
        $account = null;
        if ($this->source_control_account_id !== '' && auth()->user() !== null) {
            $account = app(GitIdentityResolver::class)->forId(auth()->user(), $this->source_control_account_id);
        }

        return app(DefaultBranchResolver::class)->resolve($cloneUrl, $account);
    }

    /**
     * URL-first detection for source mode — clone the repo and surface the
     * detected runtime / framework / port in the shared panel. No-op in
     * image mode (there's no repo to inspect). Non-blocking: a clone failure
     * lands in `$detectedPlan['error']` and never blocks {@see deploy()}.
     */
    public function detectFromRepository(): void
    {
        if ($this->mode !== 'source') {
            return;
        }

        $this->runDetection($this->normalizeToCloneUrl($this->repo), $this->branch);
    }

    public function updatedPort(): void
    {
        $this->portOverridesTouched = true;
    }

    private function loadRepositoriesForSelectedAccount(): void
    {
        if ($this->source_control_account_id === '') {
            $this->availableRepositories = [];

            return;
        }

        $account = auth()->user() !== null
            ? app(GitIdentityResolver::class)->forId(auth()->user(), $this->source_control_account_id)
            : null;
        $this->availableRepositories = $account
            ? app(SourceControlRepositoryBrowser::class)->repositoriesForAccount($account)
            : [];
    }

    private function normalizeRepo(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://github\.com/([^/]+/[^/]+?)(?:\.git)?/?$#i', $value, $m) === 1) {
            return $m[1];
        }

        return trim($value, '/');
    }
}
