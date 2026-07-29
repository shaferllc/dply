<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire\Concerns;

use App\Modules\SourceControl\Services\GitIdentityResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Git UX for serverless create: auto runtime detection hooks for
 * {@see \App\Livewire\Concerns\Sites\ConfiguresGitRepository}, app-name
 * seeding from the repo, and an Edge-style branch/tag/commit picker that
 * works before a Site row exists.
 *
 * Host must also use ConfiguresGitRepository + DetectsRepositoryRuntime and
 * implement detectFromRepository().
 */
trait ManagesServerlessCreateGit
{
    public bool $refPickerOpen = false;

    public string $refPickerTab = 'branches';

    public string $refPickerSearch = '';

    /** @var list<array{label: string, sha: string, meta: ?string}> */
    public array $refPickerResults = [];

    public ?string $refPickerError = null;

    public bool $refPickerLoading = false;

    protected function onRepositorySelected(): void
    {
        $this->maybeSeedAppNameFromRepo();
        $this->detectFromRepository();
    }

    protected function onRepositoryAutoselected(): void
    {
        $this->maybeSeedAppNameFromRepo();
        $this->detectFromRepository();
    }

    protected function onManualRepoUrlChanged(): void
    {
        $this->maybeSeedAppNameFromRepo();

        if (trim($this->git_repository_url) === '') {
            $this->detectedPlan = [];
            $this->applyDetectedRuntimePrefills();

            return;
        }

        $this->detectFromRepository();
    }

    public function updatedGitBranch(): void
    {
        if (trim($this->git_repository_url) !== '') {
            $this->detectFromRepository();
        }
    }

    protected function maybeSeedAppNameFromRepo(): void
    {
        if (trim($this->name) !== '') {
            return;
        }

        $repoName = $this->extractRepoNameForApp($this->git_repository_url);
        if ($repoName !== '') {
            $this->name = $repoName;
        }
    }

    protected function extractRepoNameForApp(string $value): string
    {
        $value = trim($value, " \t\n\r/");
        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://[^/]+/(.+)$#i', $value, $m) === 1) {
            $value = $m[1];
        } elseif (preg_match('#^git@[^:]+:(.+)$#i', $value, $m) === 1) {
            $value = $m[1];
        }

        $value = preg_replace('/\.git$/i', '', $value) ?? $value;
        $parts = array_filter(explode('/', trim($value, '/')));
        $name = (string) end($parts);

        return strtolower(trim($name));
    }

    public function openRefPicker(): void
    {
        if (trim($this->git_repository_url) === '') {
            $this->toastError(__('Choose a repository first.'));

            return;
        }

        $this->refPickerOpen = true;
        $this->refPickerTab = match ($this->git_ref_kind) {
            'tag' => 'tags',
            'commit' => 'commits',
            default => 'branches',
        };
        $this->refreshRefPicker();
    }

    public function closeRefPicker(): void
    {
        $this->refPickerOpen = false;
    }

    public function setRefPickerTab(string $tab): void
    {
        if (! in_array($tab, ['branches', 'tags', 'commits'], true)) {
            return;
        }

        $this->refPickerTab = $tab;
        $this->refreshRefPicker();
    }

    public function updatedRefPickerSearch(): void
    {
        if ($this->refPickerOpen) {
            $this->refreshRefPicker();
        }
    }

    public function selectRefPickerValue(string $value, string $kind): void
    {
        if (! in_array($kind, ['branch', 'tag', 'commit'], true)) {
            return;
        }

        $this->git_branch = $value;
        $this->git_ref_kind = $kind;
        $this->refPickerOpen = false;
        $this->detectFromRepository();
    }

    private function refreshRefPicker(): void
    {
        $this->refPickerLoading = true;
        $this->refPickerError = null;
        $this->refPickerResults = [];

        try {
            $ownerName = $this->parseGitHubOwnerName(trim($this->git_repository_url));
            if ($ownerName === null) {
                $this->refPickerError = __('Ref picker currently supports GitHub repos. Enter a branch / tag / SHA manually for other hosts.');

                return;
            }

            [$owner, $name] = $ownerName;
            $http = $this->githubHttpClient();

            if ($this->refPickerTab === 'branches') {
                $response = $http->get("/repos/{$owner}/{$name}/branches", ['per_page' => 100]);
                if (! $response->successful()) {
                    $this->refPickerError = (string) ($response->json('message') ?: __('Could not load branches.'));

                    return;
                }
                $this->refPickerResults = $this->filterRefPickerResults(
                    collect($response->json() ?? [])->map(fn (array $b): array => [
                        'label' => (string) ($b['name'] ?? ''),
                        'sha' => (string) ($b['commit']['sha'] ?? ''),
                        'meta' => ! empty($b['protected']) ? __('protected') : null,
                    ])->all(),
                );
            } elseif ($this->refPickerTab === 'tags') {
                $response = $http->get("/repos/{$owner}/{$name}/tags", ['per_page' => 100]);
                if (! $response->successful()) {
                    $this->refPickerError = (string) ($response->json('message') ?: __('Could not load tags.'));

                    return;
                }
                $this->refPickerResults = $this->filterRefPickerResults(
                    collect($response->json() ?? [])->map(fn (array $t): array => [
                        'label' => (string) ($t['name'] ?? ''),
                        'sha' => (string) ($t['commit']['sha'] ?? ''),
                        'meta' => null,
                    ])->all(),
                );
            } else {
                $branchForCommits = trim($this->git_branch) !== '' && ($this->git_ref_kind ?? 'branch') !== 'commit'
                    ? trim($this->git_branch)
                    : 'main';
                $response = $http->get("/repos/{$owner}/{$name}/commits", [
                    'sha' => $branchForCommits,
                    'per_page' => 30,
                ]);
                if (! $response->successful()) {
                    $this->refPickerError = (string) ($response->json('message') ?: __('Could not load commits.'));

                    return;
                }
                $this->refPickerResults = $this->filterRefPickerResults(
                    collect($response->json() ?? [])->map(function (array $c): array {
                        $sha = (string) ($c['sha'] ?? '');
                        $msg = (string) ($c['commit']['message'] ?? '');
                        $firstLine = explode("\n", $msg, 2)[0] ?? '';

                        return [
                            'label' => substr($sha, 0, 7),
                            'sha' => $sha,
                            'meta' => trim($firstLine),
                        ];
                    })->all(),
                );
            }
        } catch (\Throwable $e) {
            $this->refPickerError = $e->getMessage();
        } finally {
            $this->refPickerLoading = false;
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function parseGitHubOwnerName(string $repo): ?array
    {
        if ($repo === '') {
            return null;
        }

        if (preg_match('#^https?://github\.com/([^/]+)/([^/.?#]+?)(?:\.git)?(?:[/?#].*)?$#i', $repo, $m) === 1) {
            return [$m[1], $m[2]];
        }
        if (preg_match('#^git@github\.com:([^/]+)/([^/.?#]+?)(?:\.git)?$#i', $repo, $m) === 1) {
            return [$m[1], $m[2]];
        }
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) === 1) {
            [$owner, $name] = explode('/', $repo, 2);

            return [$owner, $name];
        }

        return null;
    }

    private function githubHttpClient(): PendingRequest
    {
        $client = Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->timeout(8)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);

        $user = auth()->user();
        if ($user !== null) {
            $identity = null;
            if ($this->repo_source === 'provider' && $this->source_control_account_id !== '') {
                $identity = app(GitIdentityResolver::class)->forId($user, $this->source_control_account_id);
            }
            $identity ??= app(GitIdentityResolver::class)->forUserProvider($user, 'github');
            $token = $identity?->accessToken();
            if (is_string($token) && $token !== '') {
                $client = $client->withToken($token);
            }
        }

        return $client;
    }

    /**
     * @param  list<array{label: string, sha: string, meta: ?string}>  $rows
     * @return list<array{label: string, sha: string, meta: ?string}>
     */
    private function filterRefPickerResults(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => ($r['sha'] ?? '') !== ''));
        $search = mb_strtolower(trim($this->refPickerSearch));
        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $r) use ($search): bool {
            foreach (['label', 'sha', 'meta'] as $field) {
                $value = mb_strtolower((string) ($r[$field] ?? ''));
                if ($value !== '' && str_contains($value, $search)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
