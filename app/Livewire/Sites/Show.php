<?php

namespace App\Livewire\Sites;

use App\Jobs\InstallSiteNginxJob;
use App\Jobs\IssueSiteSslJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteDeployment;
use App\Services\Sites\SiteEnvPusher;
use App\Support\SiteDeployKeyGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Server $server;

    public Site $site;

    public string $git_repository_url = '';

    public string $git_branch = 'main';

    public string $post_deploy_command = '';

    public string $env_file_content = '';

    public string $new_domain_hostname = '';

    public ?string $flash_success = null;

    public ?string $flash_error = null;

    public ?string $revealed_webhook_secret = null;

    public function mount(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }
        if ($server->organization_id !== auth()->user()->currentOrganization()?->id) {
            abort(404);
        }

        $this->authorize('view', $site);
        $this->server = $server;
        $this->site = $site;
        $this->syncFormFromSite();
    }

    protected function syncFormFromSite(): void
    {
        $this->site->refresh();
        $this->git_repository_url = (string) ($this->site->git_repository_url ?? '');
        $this->git_branch = (string) ($this->site->git_branch ?: 'main');
        $this->post_deploy_command = (string) ($this->site->post_deploy_command ?? '');
        $this->env_file_content = (string) ($this->site->env_file_content ?? '');
    }

    public function installNginx(): void
    {
        $this->authorize('update', $this->site);
        $this->flash_error = null;
        $this->flash_success = null;
        try {
            InstallSiteNginxJob::dispatchSync($this->site);
            $this->site->refresh();
            $this->flash_success = 'Nginx site config written and reloaded.';
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function issueSsl(): void
    {
        $this->authorize('update', $this->site);
        $this->flash_error = null;
        $this->flash_success = null;
        try {
            IssueSiteSslJob::dispatchSync($this->site);
            $this->site->refresh();
            $this->flash_success = 'SSL certificate requested. Refresh if status still updating.';
        } catch (\Throwable $e) {
            $this->site->refresh();
            $this->flash_error = $e->getMessage();
        }
    }

    public function deployNow(): void
    {
        $this->authorize('update', $this->site);
        $this->flash_error = null;
        $this->flash_success = null;
        try {
            RunSiteDeploymentJob::dispatchSync($this->site, SiteDeployment::TRIGGER_MANUAL);
            $this->site->refresh();
            $this->flash_success = 'Deployment finished.';
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function queueDeploy(): void
    {
        $this->authorize('update', $this->site);
        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);
        $this->flash_success = 'Deployment queued. Refresh deployments below in a moment.';
        $this->flash_error = null;
    }

    public function saveGit(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'git_repository_url' => 'nullable|string|max:500',
            'git_branch' => 'nullable|string|max:120',
            'post_deploy_command' => 'nullable|string|max:4000',
        ]);
        $this->site->update([
            'git_repository_url' => trim($this->git_repository_url) ?: null,
            'git_branch' => trim($this->git_branch) ?: 'main',
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
        ]);
        $this->flash_success = 'Git settings saved.';
        $this->flash_error = null;
    }

    public function generateDeployKey(): void
    {
        $this->authorize('update', $this->site);
        try {
            [$private, $public] = SiteDeployKeyGenerator::generate();
            $this->site->update([
                'git_deploy_key_private' => $private,
                'git_deploy_key_public' => $public,
            ]);
            $this->flash_success = 'New deploy key generated. Add the public key to your Git host.';
            $this->flash_error = null;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function regenerateWebhookSecret(): void
    {
        $this->authorize('update', $this->site);
        $plain = Str::random(48);
        $this->site->update(['webhook_secret' => $plain]);
        $this->revealed_webhook_secret = $plain;
        $this->flash_success = 'Webhook secret rotated. Copy it below — it will not be shown again.';
        $this->flash_error = null;
    }

    public function saveEnvDraft(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['env_file_content' => 'nullable|string|max:65535']);
        $this->site->update(['env_file_content' => $this->env_file_content]);
        $this->flash_success = '.env saved in Dply (not yet on server). Use “Push .env to server” to write the file.';
        $this->flash_error = null;
    }

    public function pushEnvToServer(SiteEnvPusher $pusher): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['env_file_content' => 'nullable|string|max:65535']);
        $this->flash_error = null;
        try {
            $path = $pusher->push($this->site, $this->env_file_content);
            $this->flash_success = '.env written to '.$path;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function addDomain(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_domain_hostname' => 'required|string|max:255|unique:site_domains,hostname',
        ]);
        SiteDomain::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($this->new_domain_hostname)),
            'is_primary' => false,
            'www_redirect' => false,
        ]);
        $this->new_domain_hostname = '';
        $this->flash_success = 'Domain added. Re-run “Install Nginx” if the site is already provisioned.';
        $this->flash_error = null;
    }

    public function removeDomain(int $domainId): void
    {
        $this->authorize('update', $this->site);
        $domain = SiteDomain::query()->where('site_id', $this->site->id)->findOrFail($domainId);
        if ($domain->is_primary && $this->site->domains()->count() === 1) {
            $this->flash_error = 'Cannot remove the only domain.';

            return;
        }
        if ($domain->is_primary) {
            $this->flash_error = 'Set another domain as primary before removing the primary domain.';

            return;
        }
        $domain->delete();
        $this->flash_success = 'Domain removed.';
        $this->flash_error = null;
    }

    public function deleteSite(): mixed
    {
        $this->authorize('delete', $this->site);
        $this->site->delete();

        return $this->redirect(route('servers.show', $this->server), navigate: true);
    }

    public function render(): View
    {
        $this->site->load(['domains', 'deployments' => fn ($q) => $q->limit(25)]);

        return view('livewire.sites.show', [
            'deployHookUrl' => $this->site->deployHookUrl(),
        ]);
    }
}
