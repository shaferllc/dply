<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\RemoveSiteRepositoryJob;
use App\Modules\Deploy\Services\DeployRepoPreflight;
use App\Modules\Deploy\Services\RepositoryCheckout;
use App\Modules\Deploy\Services\RepositoryRuntimeDetector;
use App\Modules\Deploy\Services\SiteQuickDeployCommitPoller;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Support\SiteDeployKeyGenerator;
use Illuminate\Support\Str;

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

    /** @var 'webhook'|'poll'|null */
    public ?string $quick_deploy_mode_ui = null;

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

        $this->validate($rules);

        $updates = [
            'git_repository_url' => trim($this->git_repository_url) ?: null,
            'git_branch' => trim($this->git_branch) ?: 'main',
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
        ];

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
        $this->warnIfRepositoryUnreachable();
        $this->syncFormFromSite();
    }

    /**
     * Instant feedback on save: the same control-plane ls-remote the deploy
     * preflight runs (no SSH — a short git process on the worker/web box).
     * Non-blocking — the settings still save; a dead credential or missing
     * branch surfaces as a warning toast HERE instead of at the next deploy.
     */
    private function warnIfRepositoryUnreachable(): void
    {
        $error = app(DeployRepoPreflight::class)->check($this->site->fresh());
        if ($error !== null) {
            $firstLine = trim((string) strtok($error, "\n"));
            $this->toastError(__('Saved, but the repository check failed: :reason', ['reason' => Str::limit($firstLine, 180)]));
        }
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
        $this->warnIfRepositoryUnreachable();
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

        $result = $provisioner->enable($this->site, $account);
        if (! $result['ok']) {
            $this->toastError($result['message']);
        } else {
            $this->toastSuccess($result['message']);
        }
        $this->syncFormFromSite();
    }

    public function enableQuickDeployPoll(RepositoryWebhookProvisioner $provisioner): void
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

        $this->site->mergeRepositoryMeta([
            'git_source_control_account_id' => $account->id(),
        ]);
        $this->site->save();

        $result = $provisioner->enablePoll($this->site);
        if (! $result['ok']) {
            $this->toastError((string) $result['message']);
        } else {
            $this->toastSuccess((string) $result['message']);
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

    /**
     * Run one Quick deploy poll tick for this site (operators shouldn't wait
     * for the scheduled ~2 minute command).
     */
    public function checkQuickDeployPollNow(SiteQuickDeployCommitPoller $poller): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot run Quick deploy checks.'));

            return;
        }

        $mode = (string) ($this->site->repositoryMeta()['quick_deploy_mode'] ?? '');
        if (! ($this->site->repositoryMeta()['quick_deploy_enabled'] ?? false) || $mode !== 'poll') {
            $this->toastError(__('Enable poll delivery before checking for new commits.'));

            return;
        }

        $result = $poller->poll($this->site->fresh() ?? $this->site);
        $this->site->refresh();
        $this->syncFormFromSite();

        if (! $result['checked']) {
            $this->toastError(__('Poll check skipped (:reason).', [
                'reason' => (string) $result['reason'],
            ]));

            return;
        }

        $message = is_string($result['message'] ?? null) && $result['message'] !== ''
            ? (string) $result['message']
            : __('Checked Git for new commits.');

        if ($result['dispatched'] || $result['outcome'] === 'deploy_queued') {
            $this->toastSuccess($message);

            return;
        }

        if ($result['outcome'] === 'error') {
            $this->toastError($message);

            return;
        }

        $this->toastSuccess($message);
    }

    public function queueRemoveRemoteRepository(): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot remove the repository checkout.'));

            return;
        }

        if ($this->site->usesDockerRuntime() || $this->site->usesKubernetesRuntime()) {
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
            if ($repository['url'] !== $value) {
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

    /**
     * Target capabilities handed to the runtime detector.
     *
     * ServerlessTargetCapabilityResolver resolved this per host (DO Functions
     * / AWS Lambda) and was deleted with the serverless module
     * (remove-cloud-edge-serverless). No serverless target survives, so this is
     * that resolver's "unknown target" branch, inlined: detection still runs
     * and reports nothing supported rather than fataling.
     *
     * @return array<string, mixed>
     */
    private static function detectionCapabilities(): array
    {
        return [
            'target' => 'unknown',
            'supports_runtime_detection' => false,
            'supports_php_runtime' => false,
            'supports_node_runtime' => false,
            'supports_python_runtime' => false,
            'supports_go_runtime' => false,
            'default_runtime' => '',
            'default_php_runtime' => '',
            'default_python_runtime' => 'python3.12',
            'default_entrypoint' => '',
            'default_package' => '',
            'host_label' => 'Unknown',
            'features' => [],
        ];
    }

    private function refreshFunctionsDetection(): void
    {
    }

    public function generateDeployKey(): void
    {
        $this->authorize('update', $this->site);

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
    }
}
