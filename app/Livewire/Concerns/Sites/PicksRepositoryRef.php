<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Models\User;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SiteGitCommitsFetcher;
use App\Modules\SourceControl\Services\SourceControlRepositoryReader;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Generic ref picker for site create/settings flows. Mirrors the Edge
 * deploy picker but isn't gated on the Edge runtime and doesn't trigger a
 * deploy — picking a ref just updates local component state. The host
 * component is responsible for persisting the selection on save.
 *
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait PicksRepositoryRef
{
    use DispatchesToastNotifications;

    public string $repo_ref_selected_sha = '';

    public ?string $repo_ref_selected_label = null;

    public ?string $repo_ref_selected_kind = null;

    public bool $repo_ref_picker_open = false;

    public string $repo_ref_tab = 'branches';

    public string $repo_ref_search = '';

    public string $repo_ref_commits_branch = '';

    /** @var list<array<string, mixed>> */
    public array $repo_ref_results = [];

    public ?string $repo_ref_error = null;

    public ?string $repo_ref_needs_provider = null;

    public function openRepoRefPicker(): void
    {
        $this->authorize('update', $this->site);

        $this->repo_ref_commits_branch = $this->repo_ref_selected_label !== null
            && $this->repo_ref_selected_label !== ''
            && $this->repo_ref_selected_kind === 'branch'
            ? $this->repo_ref_selected_label
            : (string) ($this->site->git_branch ?: 'main');

        $this->repo_ref_picker_open = true;
        $this->refreshRepoRefs();
    }

    public function closeRepoRefPicker(): void
    {
        $this->repo_ref_picker_open = false;
    }

    public function setRepoRefTab(string $tab): void
    {
        if (! in_array($tab, ['branches', 'tags', 'commits'], true)) {
            return;
        }

        $this->repo_ref_tab = $tab;
        $this->refreshRepoRefs();
    }

    public function updatedRepoRefSearch(): void
    {
        if ($this->repo_ref_picker_open) {
            $this->refreshRepoRefs();
        }
    }

    public function updatedRepoRefCommitsBranch(): void
    {
        if ($this->repo_ref_picker_open && $this->repo_ref_tab === 'commits') {
            $this->refreshRepoRefs();
        }
    }

    /**
     * Picker row click: write the chosen ref into selected_* state. The host
     * component reads these on save.
     */
    public function selectRepoRef(string $sha): void
    {
        $sha = strtolower(trim($sha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            return;
        }

        $this->repo_ref_selected_sha = $sha;

        $label = null;
        $kind = null;
        if ($this->repo_ref_tab === 'branches' || $this->repo_ref_tab === 'tags') {
            foreach ($this->repo_ref_results as $ref) {
                if (strtolower(trim((string) ($ref['sha'] ?? ''))) !== $sha) {
                    continue;
                }
                $label = trim((string) ($ref['label'] ?? '')) ?: null;
                $kind = $label !== null ? $this->repo_ref_tab === 'branches' ? 'branch' : 'tag' : null;
                break;
            }
        } else {
            $branch = trim($this->repo_ref_commits_branch);
            $label = $sha;
            $kind = 'commit';
            // Persist the branch we found this commit on (handy for the
            // deployer if the user hasn't separately picked a base ref).
            $this->repo_ref_commits_branch = $branch;
        }

        $this->repo_ref_selected_label = $label;
        $this->repo_ref_selected_kind = $kind;
        $this->closeRepoRefPicker();

        if (method_exists($this, 'onRepoRefSelected')) {
            $this->onRepoRefSelected();
        }
    }

    /**
     * Direct SHA paste — the host component can show a small input next to
     * the picker for users who already know the commit they want.
     */
    public function applyRepoRefSha(string $sha): void
    {
        $sha = strtolower(trim($sha));
        if (preg_match('/^[a-f0-9]{7,40}$/', $sha) !== 1) {
            $this->toastError(__('Enter a valid commit SHA (7–40 hex characters).'));

            return;
        }

        $this->repo_ref_selected_sha = $sha;
        $this->repo_ref_selected_label = $sha;
        $this->repo_ref_selected_kind = 'commit';

        if (method_exists($this, 'onRepoRefSelected')) {
            $this->onRepoRefSelected();
        }
    }

    public function clearRepoRefSelection(): void
    {
        $this->repo_ref_selected_sha = '';
        $this->repo_ref_selected_label = null;
        $this->repo_ref_selected_kind = null;
    }

    #[On('source-control-linked')]
    public function onRepoRefSourceControlLinked(): void
    {
        if ($this->repo_ref_picker_open) {
            $this->refreshRepoRefs();
        }
    }

    private function refreshRepoRefs(): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->repo_ref_results = [];
            $this->repo_ref_error = __('Sign in to browse repository refs.');
            $this->repo_ref_needs_provider = null;

            return;
        }

        $search = mb_strtolower(trim($this->repo_ref_search));

        if ($this->repo_ref_tab === 'branches') {
            $result = app(SourceControlRepositoryReader::class)->branches($this->site, $user);
            if (! ($result['ok'] ?? false)) {
                $this->repo_ref_results = [];
                $this->repo_ref_error = (string) ($result['error'] ?? __('Could not load branches.'));
                $this->repo_ref_needs_provider = $this->detectRepoRefProviderGap($user, $result);

                return;
            }

            $this->repo_ref_error = null;
            $this->repo_ref_needs_provider = null;
            $this->repo_ref_results = $this->filterRepoRefs(
                collect($result['branches'] ?? [])
                    ->map(fn (array $branch): array => [
                        'kind' => 'branch',
                        'label' => (string) ($branch['name'] ?? ''),
                        'sha' => (string) ($branch['sha'] ?? ''),
                        'meta' => ($branch['is_default'] ?? false) ? __('Default branch') : null,
                    ])
                    ->all(),
                $search,
                ['label'],
            );

            return;
        }

        if ($this->repo_ref_tab === 'tags') {
            $result = app(SourceControlRepositoryReader::class)->tags($this->site, $user);
            if (! ($result['ok'] ?? false)) {
                $this->repo_ref_results = [];
                $this->repo_ref_error = (string) ($result['error'] ?? __('Could not load tags.'));
                $this->repo_ref_needs_provider = $this->detectRepoRefProviderGap($user, $result);

                return;
            }

            $this->repo_ref_error = null;
            $this->repo_ref_needs_provider = null;
            $this->repo_ref_results = $this->filterRepoRefs(
                collect($result['tags'] ?? [])
                    ->map(fn (array $tag): array => [
                        'kind' => 'tag',
                        'label' => (string) ($tag['name'] ?? ''),
                        'sha' => (string) ($tag['sha'] ?? ''),
                        'meta' => null,
                    ])
                    ->all(),
                $search,
                ['label'],
            );

            return;
        }

        $branch = trim($this->repo_ref_commits_branch) !== '' ? trim($this->repo_ref_commits_branch) : null;
        $result = app(SiteGitCommitsFetcher::class)->fetch($this->site, $user, 40, $branch);
        if (! ($result['ok'] ?? false)) {
            $this->repo_ref_results = [];
            $this->repo_ref_error = (string) ($result['error'] ?? __('Could not load commits.'));
            $this->repo_ref_needs_provider = $this->detectRepoRefProviderGap($user, $result);

            return;
        }

        $this->repo_ref_error = null;
        $this->repo_ref_needs_provider = null;
        $this->repo_ref_results = $this->filterRepoRefs(
            collect($result['commits'] ?? [])
                ->map(fn (array $commit): array => [
                    'kind' => 'commit',
                    'label' => (string) ($commit['short_sha'] ?? substr((string) ($commit['sha'] ?? ''), 0, 7)),
                    'sha' => (string) ($commit['sha'] ?? ''),
                    'meta' => Str::limit((string) ($commit['message'] ?? ''), 72),
                ])
                ->all(),
            $search,
            ['label', 'sha', 'meta'],
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function detectRepoRefProviderGap(User $user, array $result): ?string
    {
        $provider = (string) ($result['provider'] ?? '');
        if ($provider === '' || ! in_array($provider, ['github', 'gitlab', 'bitbucket'], true)) {
            return null;
        }

        return app(GitIdentityResolver::class)->forUserProvider($user, $provider) === null
            ? $provider
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function filterRepoRefs(array $rows, string $search, array $fields): array
    {
        if ($search === '') {
            return array_values(array_filter($rows, fn (array $row): bool => ($row['sha'] ?? '') !== ''));
        }

        return array_values(array_filter($rows, function (array $row) use ($search, $fields): bool {
            if (($row['sha'] ?? '') === '') {
                return false;
            }

            foreach ($fields as $field) {
                $value = mb_strtolower((string) ($row[$field] ?? ''));
                if ($value !== '' && str_contains($value, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
