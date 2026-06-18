<?php

namespace App\Livewire\Sites;

use App\Enums\SiteType;
use App\Jobs\ProvisionCustomSiteJob;
use App\Livewire\Concerns\EnforcesSiteQuota;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Livewire\Concerns\Sites\ConfiguresGitRepository;
use App\Models\Script;
use App\Models\Server;
use App\Models\Site;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateCustom extends Component
{
    use ConfiguresGitRepository;
    use EnforcesSiteQuota;
    use RefreshesLinkedSourceControlAccounts;

    public Server $server;

    public string $name = '';

    public string $system_user_override = '';

    // The Git-repository picker state (repo_source, source_control_account_id,
    // repository_selection, git_repository_url, git_branch, availableRepositories,
    // linkedSourceControlAccounts) + its updated* hooks live in the
    // ConfiguresGitRepository trait.

    public function mount(Server $server, SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        if ($server->hostKind() !== Server::HOST_KIND_VM) {
            throw new AuthorizationException(
                'Custom sites are only supported on VM (SSH) hosts.'
            );
        }

        $this->server = $server;
        $this->system_user_override = (string) $server->ssh_user;

        // Preload connected accounts; default to the provider picker when the
        // user already has one linked, otherwise fall back to manual URL entry.
        $user = auth()->user();
        $this->linkedSourceControlAccounts = $user ? $repositoryBrowser->accountsForUser($user) : [];
        if ($this->linkedSourceControlAccounts !== []) {
            $this->repo_source = 'provider';
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
            $this->refreshRepositories($repositoryBrowser);
        }
    }

    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        // A provider was just linked via the modal — switch to the picker and
        // load its repos so the new account is immediately usable.
        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        $this->repo_source = 'provider';
        if ($this->source_control_account_id === '') {
            $this->source_control_account_id = (string) $this->linkedSourceControlAccounts[0]['id'];
        }
        $this->refreshRepositories(app(SourceControlRepositoryBrowser::class));
    }

    public function store(): mixed
    {
        $hasRepo = trim($this->git_repository_url) !== '';

        $rules = [
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'system_user_override' => ['nullable', 'string', 'max:64', 'regex:/^[a-z_][a-z0-9_-]*$/i'],
            'repo_source' => ['required', 'in:manual,provider'],
        ];

        if ($hasRepo) {
            $rules['git_repository_url'] = ['required', 'string', 'max:500'];
            $rules['git_branch'] = ['required', 'string', 'max:120'];

            // In provider mode a repository must actually be picked (the picker
            // mirrors its URL into git_repository_url, but guard the empty case).
            if ($this->repo_source === 'provider') {
                $rules['source_control_account_id'] = ['required', 'string', 'max:26'];
                $rules['repository_selection'] = ['required', 'string', 'max:500'];
            }
        }

        $this->validate($rules);

        if ($this->siteQuotaReached($this->server->organization)) {
            return null;
        }

        $slug = $this->buildSlug();
        $systemUser = trim($this->system_user_override) !== ''
            ? trim($this->system_user_override)
            : (string) $this->server->ssh_user;

        $script = Script::create([
            'organization_id' => $this->server->organization_id,
            'user_id' => $this->server->user_id,
            'name' => "Deploy {$this->name}",
            'content' => $this->stubScriptContent($hasRepo),
            'run_as_user' => $systemUser,
            'source' => 'site:custom_auto',
        ]);

        $site = Site::create([
            'server_id' => $this->server->id,
            'user_id' => $this->server->user_id,
            'organization_id' => $this->server->organization_id,
            'workspace_id' => $this->server->workspace_id ?? null,
            'name' => $this->name,
            'slug' => $slug,
            'type' => SiteType::Custom,
            'repository_path' => "/home/{$systemUser}/{$slug}",
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'git_repository_url' => $hasRepo ? $this->git_repository_url : null,
            'git_branch' => $hasRepo ? $this->git_branch : null,
            'deploy_script_id' => $script->id,
            'php_fpm_user' => $systemUser !== (string) $this->server->ssh_user ? $systemUser : null,
            'deploy_strategy' => 'simple',
        ]);

        ProvisionCustomSiteJob::dispatch($site->id);

        return redirect()->route('sites.deployments.index', [
            'server' => $this->server,
            'site' => $site,
        ]);
    }

    public function render(): View
    {
        return view('livewire.sites.create-custom');
    }

    private function buildSlug(): string
    {
        $base = Str::slug($this->name) ?: 'custom-site';
        $slug = $base;
        $suffix = 1;

        while (Site::where('server_id', $this->server->id)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function stubScriptContent(bool $hasRepo): string
    {
        $mode = $hasRepo ? 'git-mode' : 'no-repo mode';

        return <<<BASH
            #!/usr/bin/env bash
            # Custom site deploy script — {$mode}
            # Runs in the site's working directory as its system user.
            #
            # Env vars available:
            #   DEPLOY_PATH   absolute path to the site directory
            #   RELEASE_REF   git ref being deployed (git-mode only)
            #   SITE_NAME     the site name
            set -euo pipefail

            cd "\$DEPLOY_PATH"

            # put your deploy steps here

            echo "Deployment complete."
            BASH;
    }
}
