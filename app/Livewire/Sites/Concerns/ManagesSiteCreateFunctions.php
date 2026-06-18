<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Services\Deploy\ServerlessRepositoryCheckout;
use App\Services\Deploy\ServerlessRuntimeDetector;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteCreateFunctions
{
    public function updatedFormFunctionsRepoSource(): void
    {
        if ($this->form->functions_repo_source === 'manual') {
            $this->form->functions_source_control_account_id = '';
            $this->form->functions_repository_selection = '';
            $this->availableFunctionsRepositories = [];

            return;
        }

        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        $this->form->functions_source_control_account_id = $this->linkedSourceControlAccounts[0]['id'];
        $this->updatedFormFunctionsSourceControlAccountId($this->form->functions_source_control_account_id);
    }

    public function updatedFormFunctionsSourceControlAccountId(string $value): void
    {
        $this->form->functions_source_control_account_id = $value;
        $this->form->functions_repository_selection = '';
        $this->availableFunctionsRepositories = [];

        if ($value === '') {
            return;
        }

        $account = app(GitIdentityResolver::class)->forId(auth()->user(), $value);
        if ($account === null) {
            return;
        }

        $this->availableFunctionsRepositories = app(SourceControlRepositoryBrowser::class)
            ->repositoriesForAccount($account);
    }

    public function updatedFormFunctionsRepositorySelection(string $value): void
    {
        foreach ($this->availableFunctionsRepositories as $repository) {
            if (($repository['url'] ?? null) !== $value) {
                continue;
            }

            $this->form->functions_repository_url = (string) $repository['url'];
            $this->form->functions_repository_branch = (string) ($repository['branch'] ?: 'main');
            $this->refreshFunctionsDetection();

            return;
        }
    }

    public function updatedFormFunctionsRepositoryUrl(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFormFunctionsRepositoryBranch(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFormFunctionsRepositorySubdirectory(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFormFunctionsBuildCommand(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFormFunctionsArtifactOutputPath(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFormFunctionsRuntime(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFormFunctionsEntrypoint(): void
    {
        $this->functionsOverridesTouched = true;
    }

    private function refreshFunctionsDetection(): void
    {
        if (! $this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return;
        }

        $repositoryUrl = trim($this->form->functions_repository_url);
        $branch = trim($this->form->functions_repository_branch);

        if ($repositoryUrl === '' || $branch === '') {
            $this->functionsDetection = [];

            return;
        }

        $checkout = null;

        try {
            $checkout = app(ServerlessRepositoryCheckout::class)->checkout(
                'preview-create-'.(string) auth()->id().'-'.md5($repositoryUrl.'|'.$branch.'|'.$this->form->functions_repository_subdirectory),
                $repositoryUrl,
                $branch,
                $this->form->functions_repository_subdirectory,
                auth()->id(),
                $this->form->functions_repo_source === 'provider' ? $this->form->functions_source_control_account_id : null,
            );

            $this->functionsDetection = app(ServerlessRuntimeDetector::class)->detect(
                $checkout['working_directory'],
                app(ServerlessTargetCapabilityResolver::class)->forServer($this->server),
            );

            if (! $this->functionsOverridesTouched) {
                $this->form->functions_runtime = (string) ($this->functionsDetection['runtime'] ?? $this->form->functions_runtime);
                $this->form->functions_entrypoint = (string) ($this->functionsDetection['entrypoint'] ?? $this->form->functions_entrypoint);
                $this->form->functions_build_command = (string) ($this->functionsDetection['build_command'] ?? $this->form->functions_build_command);
                $this->form->functions_artifact_output_path = (string) ($this->functionsDetection['artifact_output_path'] ?? $this->form->functions_artifact_output_path);
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

    private function loadFunctionsSourceControlState(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());

        if ($this->linkedSourceControlAccounts === []) {
            $this->form->functions_repo_source = 'manual';

            return;
        }

        if ($this->form->functions_repo_source === 'manual') {
            $this->form->functions_repo_source = 'provider';
        }

        if ($this->form->functions_source_control_account_id === '') {
            $this->form->functions_source_control_account_id = $this->linkedSourceControlAccounts[0]['id'];
        }

        $account = app(GitIdentityResolver::class)->forId(auth()->user(), $this->form->functions_source_control_account_id);
        $this->availableFunctionsRepositories = $account
            ? $repositoryBrowser->repositoriesForAccount($account)
            : [];
    }
}
