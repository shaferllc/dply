<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Livewire\Sites\Commits;
use App\Services\SourceControl\SourceControlRepositoryReader;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesRepositoryBrowsing
{


    /** Reset to the first page whenever the filter changes. */
    public function updatedCommitFilter(): void
    {
        $this->commitsPage = 1;
    }

    /** Step the Commits tab one page in either direction (never below 1). */
    public function changeCommitsPage(int $delta): void
    {
        $this->commitsPage = max(1, $this->commitsPage + $delta);
    }

    /**
     * Re-fetch the repository panels (commits / files / README) from the Git
     * provider. render() loads that data fresh on every pass, so simply
     * returning here triggers a Livewire re-render that re-runs the provider
     * API calls — giving the error states a reliable "Retry" affordance after a
     * transient provider 404/5xx or once repo access is fixed, without a full
     * page reload.
     */
    public function reloadRepository(): void
    {
        // Commits + reader (files / branches / README) are cached per site under a
        // shared version key; bump it so this re-render genuinely re-fetches from
        // the provider instead of serving the cached error/stale page.
        app(SourceControlRepositoryReader::class)->invalidate($this->site);
    }

    public function navigateToPath(string $path): void
    {
        $this->filesPath = trim($path, '/');
        $this->filesOpenFile = '';
    }

    public function openFile(string $path): void
    {
        $this->filesOpenFile = trim($path, '/');
    }

    public function closeFile(): void
    {
        $this->filesOpenFile = '';
    }

    public function switchBranch(string $branch, SourceControlRepositoryReader $reader): void
    {
        Gate::authorize('update', $this->site);

        $branch = trim($branch);
        if ($branch === '') {
            $this->toastError(__('Pick a branch first.'));

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['git_ref_kind'] = 'branch';
        $this->site->forceFill([
            'git_branch' => $branch,
            'meta' => $meta,
        ])->save();
        $reader->invalidate($this->site);
        $this->git_branch = $branch;
        $this->git_ref_kind = 'branch';
        $this->branchOverride = '';
        $this->toastSuccess(__('Deploy branch set to :branch.', ['branch' => $branch]));
    }
}
