<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\RemoveSiteRepositoryJob;
use App\Services\Deploy\ServerlessRepositoryCheckout;
use App\Services\Deploy\ServerlessRuntimeDetector;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Support\SiteDeployKeyGenerator;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteRepositoryConfig
{
    public string $git_repository_url = '';

    public string $git_branch = 'main';

    public string $functions_repo_source = 'manual';

    public string $functions_source_control_account_id = '';

    public string $functions_repository_selection = '';

    public string $functions_repository_subdirectory = '';

    public string $functions_runtime = '';

    public string $functions_entrypoint = '';

    public string $functions_build_command = '';

    public string $functions_artifact_output_path = '';

    /** @var 'github'|'gitlab'|'bitbucket'|'custom' */
    public string $git_provider_kind = 'custom';

    public string $git_source_control_account_id = '';

    public bool $quick_deploy_enabled_ui = false;

    /**
     * @var list<array{id: string, provider: string, label: string}>
     */
    public array $linkedSourceControlAccounts = [];

    /**
     * @var list<array{label: string, url: string, branch: string}>
     */
    public array $availableFunctionsRepositories = [];

    /**
     * @var array<string, mixed>
     */
    public array $functionsDetection = [];

    public bool $functionsOverridesTouched = false;

    public function saveGit(): void
    {
        $this->authorize('update', $this->site);
        $rules = [
            'git_repository_url' => 'nullable|string|max:500',
            'git_branch' => 'nullable|string|max:120',
            'post_deploy_command' => 'nullable|string|max:4000',
        ];

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            if (($this->functionsDetection['unsupported_for_target'] ?? false) === true) {
                $this->toastError((string) ($this->functionsDetection['warnings'][0] ?? __('This repository runtime is not supported by the selected target.')));

                return;
            }

            $rules = array_merge($rules, [
                'functions_repo_source' => 'required|string|in:manual,provider',
                'functions_source_control_account_id' => 'nullable|string|max:26',
                'functions_repository_selection' => 'nullable|string|max:500',
                'functions_repository_subdirectory' => 'nullable|string|max:255',
                'functions_runtime' => 'required|string|max:50',
                'functions_entrypoint' => 'required|string|max:255',
                'functions_build_command' => 'required|string|max:4000',
                'functions_artifact_output_path' => 'required|string|max:255',
                'git_repository_url' => 'required|string|max:500',
                'git_branch' => 'required|string|max:120',
            ]);

            if ($this->functions_repo_source === 'provider') {
                $rules['functions_source_control_account_id'] = 'required|string|max:26';
            }
        }

        $this->validate($rules);

        $updates = [
            'git_repository_url' => trim($this->git_repository_url) ?: null,
            'git_branch' => trim($this->git_branch) ?: 'main',
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
        ];

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $meta = is_array($this->site->meta) ? $this->site->meta : [];
            $functionsConfig = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
            $meta['serverless'] = array_merge($functionsConfig, [
                'repo_source' => trim($this->functions_repo_source),
                'source_control_account_id' => $this->functions_repo_source === 'provider'
                    ? trim($this->functions_source_control_account_id)
                    : null,
                'repository_subdirectory' => trim($this->functions_repository_subdirectory),
                'runtime' => trim($this->functions_runtime),
                'entrypoint' => trim($this->functions_entrypoint),
                'build_command' => trim($this->functions_build_command),
                'artifact_output_path' => trim($this->functions_artifact_output_path),
                'detected_runtime' => $this->functionsDetection !== [] ? $this->functionsDetection : null,
            ]);
            $updates['meta'] = $meta;
        }

        $oldRepoSnapshot = [
            'git_repository_url' => $this->site->git_repository_url,
            'git_branch' => $this->site->git_branch,
            'post_deploy_command' => $this->site->post_deploy_command,
        ];
        $this->site->update($updates);
        $org = $this->site->server?->organization;
        if ($org && $oldRepoSnapshot !== array_intersect_key($updates, $oldRepoSnapshot)) {
            audit_log($org, auth()->user(), 'site.repository_updated', $this->site, $oldRepoSnapshot, array_intersect_key($updates, $oldRepoSnapshot));
        }
        $this->toastSuccess('Git settings saved.');
        $this->syncFormFromSite();
    }

    public function saveRepositoryWorkspace(): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot change repository settings.'));

            return;
        }

        $rules = [
            'git_repository_url' => 'nullable|string|max:500',
            'git_branch' => 'nullable|string|max:120',
            'git_provider_kind' => 'required|string|in:github,gitlab,bitbucket,custom',
            'git_source_control_account_id' => 'nullable|string|max:26',
            'deploy_sync_include_peers_on_manual' => 'boolean',
        ];
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $rules['git_repository_url'] = 'required|string|max:500';
            $rules['git_branch'] = 'required|string|max:120';
        }
        if ($this->git_provider_kind !== 'custom' && $this->git_source_control_account_id === '') {
            $this->addError('git_source_control_account_id', __('Select a linked source control account or choose Custom.'));

            return;
        }

        $this->validate($rules);

        $this->site->mergeRepositoryMeta([
            'git_provider_kind' => $this->git_provider_kind,
            'git_source_control_account_id' => $this->git_source_control_account_id !== '' ? $this->git_source_control_account_id : null,
            'deploy_sync_include_peers_on_manual' => $this->deploy_sync_include_peers_on_manual,
        ]);

        $this->site->fill([
            'git_repository_url' => trim($this->git_repository_url) ?: null,
            'git_branch' => trim($this->git_branch) ?: 'main',
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
        ]);
        $this->site->save();
        $this->toastSuccess(__('Repository settings saved.'));
        $this->syncFormFromSite();
    }

    public function enableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot enable Quick deploy.'));

            return;
        }

        $user = request()->user();
        $account = $this->git_source_control_account_id !== '' && $user !== null
            ? app(GitIdentityResolver::class)->forId($user, $this->git_source_control_account_id)
            : null;
        if ($account === null) {
            $this->toastError(__('Select a connected source control account first.'));

            return;
        }

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! $result['ok']) {
            $this->toastError($result['message']);
        } else {
            $this->toastSuccess($result['message']);
        }
        $this->site->refresh();
        $this->syncFormFromSite();
    }

    public function disableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot change Quick deploy.'));

            return;
        }

        $provisioner->disable($this->site->fresh());
        $this->toastSuccess(__('Quick deploy disabled and provider hook removed when possible.'));
        $this->site->refresh();
        $this->syncFormFromSite();
    }

    public function queueRemoveRemoteRepository(): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot remove the repository checkout.'));

            return;
        }

        if ($this->site->usesFunctionsRuntime() || $this->site->usesDockerRuntime() || $this->site->usesKubernetesRuntime()) {
            $this->toastError(__('This runtime does not use a traditional VM repository path.'));

            return;
        }

        RemoveSiteRepositoryJob::dispatch($this->site->id);
        $this->toastSuccess(__('Repository removal has been queued. This may take a minute on large trees.'));
    }

    public function updatedFunctionsRepoSource(): void
    {
        if ($this->functions_repo_source === 'manual') {
            $this->functions_source_control_account_id = '';
            $this->functions_repository_selection = '';
            $this->availableFunctionsRepositories = [];

            $this->refreshFunctionsDetection();

            return;
        }

        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        $this->functions_source_control_account_id = $this->linkedSourceControlAccounts[0]['id'];
        $this->updatedFunctionsSourceControlAccountId($this->functions_source_control_account_id);
    }

    public function updatedFunctionsSourceControlAccountId(string $value): void
    {
        $this->functions_source_control_account_id = $value;
        $this->functions_repository_selection = '';
        $this->availableFunctionsRepositories = [];

        if ($value === '') {
            return;
        }

        $user = request()->user();
        $account = $user !== null ? app(GitIdentityResolver::class)->forId($user, $value) : null;
        if ($account === null) {
            return;
        }

        $this->availableFunctionsRepositories = app(SourceControlRepositoryBrowser::class)
            ->repositoriesForAccount($account);
    }

    public function updatedFunctionsRepositorySelection(string $value): void
    {
        foreach ($this->availableFunctionsRepositories as $repository) {
            if (($repository['url'] ?? null) !== $value) {
                continue;
            }

            $this->git_repository_url = (string) $repository['url'];
            $this->git_branch = (string) ($repository['branch'] ?: 'main');
            $this->refreshFunctionsDetection();

            return;
        }
    }

    public function updatedGitRepositoryUrl(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedGitBranch(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFunctionsRepositorySubdirectory(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFunctionsRuntime(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFunctionsEntrypoint(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFunctionsBuildCommand(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFunctionsArtifactOutputPath(): void
    {
        $this->functionsOverridesTouched = true;
    }

    private function refreshFunctionsDetection(): void
    {
        if (! $this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return;
        }

        $repositoryUrl = trim($this->git_repository_url);
        $branch = trim($this->git_branch);

        if ($repositoryUrl === '' || $branch === '') {
            $this->functionsDetection = [];

            return;
        }

        $checkout = null;

        try {
            $checkout = app(ServerlessRepositoryCheckout::class)->checkout(
                'preview-site-'.$this->site->id.'-'.md5($repositoryUrl.'|'.$branch.'|'.$this->functions_repository_subdirectory),
                $repositoryUrl,
                $branch,
                $this->functions_repository_subdirectory,
                $this->site->user_id,
                $this->functions_repo_source === 'provider' ? $this->functions_source_control_account_id : null,
            );

            $this->functionsDetection = app(ServerlessRuntimeDetector::class)->detect(
                $checkout['working_directory'],
                app(ServerlessTargetCapabilityResolver::class)->forServer($this->server),
            );

            if (! $this->functionsOverridesTouched) {
                $this->functions_runtime = (string) ($this->functionsDetection['runtime'] ?? $this->functions_runtime);
                $this->functions_entrypoint = (string) ($this->functionsDetection['entrypoint'] ?? $this->functions_entrypoint);
                $this->functions_build_command = (string) ($this->functionsDetection['build_command'] ?? $this->functions_build_command);
                $this->functions_artifact_output_path = (string) ($this->functionsDetection['artifact_output_path'] ?? $this->functions_artifact_output_path);
            }
        } catch (\Throwable $e) {
            $this->functionsDetection = [
                'framework' => 'unknown',
                'language' => 'unknown',
                'runtime' => '',
                'entrypoint' => '',
                'build_command' => '',
                'artifact_output_path' => '',
                'package' => 'default',
                'confidence' => 'low',
                'reasons' => [],
                'warnings' => [$e->getMessage()],
                'unsupported_for_target' => false,
            ];
        } finally {
            if (is_array($checkout) && isset($checkout['workspace_path']) && is_string($checkout['workspace_path'])) {
                app(ServerlessRepositoryCheckout::class)->cleanup($checkout['workspace_path']);
            }
        }
    }

    public function generateDeployKey(): void
    {
        $this->authorize('update', $this->site);
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Serverless-backed sites deploy from the configured artifact zip instead of a server-side git checkout.'));

            return;
        }

        try {
            [$private, $public] = SiteDeployKeyGenerator::generate();
            $this->site->git_deploy_key_private = $private;
            $this->site->git_deploy_key_public = $public;
            $this->site->save();
            $this->toastSuccess('New deploy key generated. Add the public key to your Git host.');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    private function loadFunctionsSourceControlState(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(request()->user());

        if (! $this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return;
        }

        if ($this->linkedSourceControlAccounts === []) {
            $this->functions_repo_source = 'manual';

            return;
        }

        if ($this->functions_repo_source === 'provider' && $this->functions_source_control_account_id === '') {
            $this->functions_source_control_account_id = $this->linkedSourceControlAccounts[0]['id'];
        }

        if ($this->functions_repo_source !== 'provider') {
            return;
        }

        $user = request()->user();
        $account = $user !== null
            ? app(GitIdentityResolver::class)->forId($user, $this->functions_source_control_account_id)
            : null;
        $this->availableFunctionsRepositories = $account
            ? $repositoryBrowser->repositoriesForAccount($account)
            : [];
    }
}
