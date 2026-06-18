<?php

declare(strict_types=1);

namespace App\Livewire\Edge\Concerns;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesEdgeRefPicker
{
    public function openRefPicker(): void
    {
        $this->refPickerOpen = true;
        $this->refPickerTab = $this->resolvePickerTabFromRefKind();
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

    /**
     * Filling the ref input: writes label into `branch` and flips
     * `form.ref_kind` so the segmented control + downstream form data
     * line up with what was picked.
     */
    public function selectRefPickerValue(string $value, string $kind): void
    {
        if (! in_array($kind, ['branch', 'tag', 'commit'], true)) {
            return;
        }
        $this->branch = $value;
        $this->form->ref_kind = $kind;
        $this->refPickerOpen = false;
        $this->maybeAutoDetectFromRepository();
    }

    private function resolvePickerTabFromRefKind(): string
    {
        return match ($this->form->ref_kind) {
            'tag' => 'tags',
            'commit' => 'commits',
            default => 'branches',
        };
    }

    /**
     * Fetch refs from the host's REST API. Currently GitHub-only;
     * GitLab/Bitbucket fall back to a friendly "use manual entry" notice.
     * Auth via the user's linked GitIdentity when available so private
     * repos work and rate limits move from 60/hr → 5000/hr.
     */
    private function refreshRefPicker(): void
    {
        $this->refPickerLoading = true;
        $this->refPickerError = null;
        $this->refPickerResults = [];

        try {
            $ownerName = $this->parseGitHubOwnerName(trim($this->repo));
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
                $this->refPickerResults = $this->filterResults(
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
                $this->refPickerResults = $this->filterResults(
                    collect($response->json() ?? [])->map(fn (array $t): array => [
                        'label' => (string) ($t['name'] ?? ''),
                        'sha' => (string) ($t['commit']['sha'] ?? ''),
                        'meta' => null,
                    ])->all(),
                );
            } else { // commits
                $branchForCommits = trim($this->branch) !== '' && $this->form->ref_kind !== 'commit'
                    ? trim($this->branch)
                    : 'main';
                $response = $http->get("/repos/{$owner}/{$name}/commits", [
                    'sha' => $branchForCommits,
                    'per_page' => 30,
                ]);
                if (! $response->successful()) {
                    $this->refPickerError = (string) ($response->json('message') ?: __('Could not load commits.'));

                    return;
                }
                $this->refPickerResults = $this->filterResults(
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
}
