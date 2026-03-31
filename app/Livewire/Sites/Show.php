<?php

namespace App\Livewire\Sites;

use App\Jobs\InstallSiteNginxJob;
use App\Jobs\IssueSiteSslJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use App\Models\SiteDomain;
use App\Models\SiteEnvironmentVariable;
use App\Models\SiteRedirect;
use App\Models\SiteRelease;
use App\Services\Sites\SiteEnvPusher;
use App\Services\Sites\SiteReleaseRollback;
use App\Support\SiteDeployKeyGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
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

    public string $deploy_strategy = 'simple';

    public int $releases_to_keep = 5;

    public string $nginx_extra_raw = '';

    public string $octane_port = '';

    public bool $laravel_scheduler = false;

    public bool $restart_supervisor_programs_after_deploy = false;

    public string $deployment_environment = 'production';

    public string $php_fpm_user = '';

    public string $new_env_key = '';

    public string $new_env_value = '';

    public string $new_env_environment = 'production';

    public string $new_redirect_from = '';

    public string $new_redirect_to = '';

    public int $new_redirect_code = 301;

    public string $new_hook_phase = 'after_clone';

    public string $new_hook_script = '';

    public int $new_hook_order = 0;

    public int $new_hook_timeout_seconds = 900;

    public string $new_deploy_step_type = SiteDeployStep::TYPE_COMPOSER_INSTALL;

    public string $new_deploy_step_command = '';

    public int $new_deploy_step_timeout = 900;

    public string $webhook_allowed_ips_text = '';

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
        $this->deploy_strategy = (string) ($this->site->deploy_strategy ?? 'simple');
        $this->releases_to_keep = (int) ($this->site->releases_to_keep ?? 5);
        $this->nginx_extra_raw = (string) ($this->site->nginx_extra_raw ?? '');
        $this->octane_port = $this->site->octane_port !== null ? (string) $this->site->octane_port : '';
        $this->laravel_scheduler = (bool) $this->site->laravel_scheduler;
        $this->restart_supervisor_programs_after_deploy = (bool) ($this->site->restart_supervisor_programs_after_deploy ?? false);
        $this->deployment_environment = (string) ($this->site->deployment_environment ?? 'production');
        $this->php_fpm_user = (string) ($this->site->php_fpm_user ?? '');
        $ips = $this->site->webhook_allowed_ips;
        $this->webhook_allowed_ips_text = is_array($ips) && $ips !== []
            ? implode("\n", $ips)
            : '';
    }

    public function saveWebhookSecurity(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'webhook_allowed_ips_text' => 'nullable|string|max:4000',
        ]);
        $lines = preg_split('/\r\n|\r|\n/', $this->webhook_allowed_ips_text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! $this->validIpOrCidr($line)) {
                $this->addError('webhook_allowed_ips_text', 'Invalid IP or CIDR: '.$line);

                return;
            }
            $clean[] = $line;
        }
        $this->site->update([
            'webhook_allowed_ips' => $clean !== [] ? $clean : null,
        ]);
        $this->flash_success = 'Webhook IP allow list saved. Leave empty to allow any source (signature still required).';
        $this->flash_error = null;
        $this->syncFormFromSite();
    }

    protected function validIpOrCidr(string $value): bool
    {
        if (str_contains($value, '/')) {
            return (bool) preg_match('#^(\d{1,3}\.){3}\d{1,3}/(3[0-2]|[12]?\d)$#', $value);
        }

        return (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
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
            $this->flash_success = config('insights.queue_after_deploy', true)
                ? __('Deployment finished. Server and site insight runs have been queued.')
                : __('Deployment finished.');
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function queueDeploy(): void
    {
        $this->authorize('update', $this->site);
        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);
        $base = __('Deployment queued. If another run is in progress, the new one may be recorded as skipped. Refresh deployments below.');
        $this->flash_success = config('insights.queue_after_deploy', true)
            ? $base.' '.__('After a successful deploy, server and site insight runs are queued automatically.')
            : $base;
        $this->flash_error = null;
    }

    public function getDeployLockInfoProperty(): ?array
    {
        return Cache::get('site-deploy-active:'.$this->site->id);
    }

    public function releaseDeployLock(): void
    {
        $this->authorize('update', $this->site);
        Cache::lock('site-deploy:'.$this->site->id)->forceRelease();
        Cache::forget('site-deploy-active:'.$this->site->id);
        $this->flash_success = 'Deploy lock cleared. If a worker is still running, stop it on the queue host; otherwise you can deploy again.';
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

    public function saveDeploymentSettings(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'deploy_strategy' => 'required|in:simple,atomic',
            'releases_to_keep' => 'required|integer|min:1|max:50',
            'nginx_extra_raw' => 'nullable|string|max:16000',
            'octane_port' => 'nullable|integer|min:1|max:65535',
            'laravel_scheduler' => 'boolean',
            'restart_supervisor_programs_after_deploy' => 'boolean',
            'deployment_environment' => 'required|string|max:32',
            'php_fpm_user' => 'nullable|string|max:64',
        ]);
        $this->site->update([
            'deploy_strategy' => $this->deploy_strategy,
            'releases_to_keep' => $this->releases_to_keep,
            'nginx_extra_raw' => $this->nginx_extra_raw !== '' ? $this->nginx_extra_raw : null,
            'octane_port' => $this->octane_port !== '' ? (int) $this->octane_port : null,
            'laravel_scheduler' => $this->laravel_scheduler,
            'restart_supervisor_programs_after_deploy' => $this->restart_supervisor_programs_after_deploy,
            'deployment_environment' => $this->deployment_environment,
            'php_fpm_user' => $this->php_fpm_user !== '' ? $this->php_fpm_user : null,
        ]);
        $this->flash_success = 'Deployment / Nginx settings saved. Re-install Nginx if you changed redirects, Octane, or extra config. Re-sync server crontab for Laravel scheduler. When “Restart Supervisor after deploy” is on, Dply restarts programs for this site (and server-wide programs) after a successful deploy.';
        $this->flash_error = null;
    }

    public function addEnvironmentVariable(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_env_key' => 'required|string|max:128|regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
            'new_env_value' => 'nullable|string|max:20000',
            'new_env_environment' => 'required|string|max:32',
        ]);
        SiteEnvironmentVariable::query()->updateOrCreate(
            [
                'site_id' => $this->site->id,
                'env_key' => $this->new_env_key,
                'environment' => $this->new_env_environment,
            ],
            ['env_value' => $this->new_env_value]
        );
        $this->new_env_key = '';
        $this->new_env_value = '';
        $this->flash_success = 'Environment variable saved.';
        $this->flash_error = null;
    }

    public function deleteEnvironmentVariable(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteEnvironmentVariable::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->flash_success = 'Variable removed.';
        $this->flash_error = null;
    }

    public function addRedirectRule(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_redirect_from' => 'required|string|max:512',
            'new_redirect_to' => 'required|string|max:1024',
            'new_redirect_code' => 'required|integer|in:301,302,307,308',
        ]);
        SiteRedirect::query()->create([
            'site_id' => $this->site->id,
            'from_path' => $this->new_redirect_from,
            'to_url' => $this->new_redirect_to,
            'status_code' => $this->new_redirect_code,
            'sort_order' => (int) ($this->site->redirects()->max('sort_order') ?? 0) + 1,
        ]);
        $this->new_redirect_from = '';
        $this->new_redirect_to = '';
        $this->new_redirect_code = 301;
        $this->flash_success = 'Redirect added. Re-run Install Nginx to apply.';
        $this->flash_error = null;
    }

    public function deleteRedirectRule(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteRedirect::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->flash_success = 'Redirect removed. Re-run Install Nginx.';
        $this->flash_error = null;
    }

    public function addDeployHook(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_hook_phase' => 'required|in:before_clone,after_clone,after_activate',
            'new_hook_script' => 'required|string|max:16000',
            'new_hook_order' => 'integer|min:0|max:999',
            'new_hook_timeout_seconds' => 'required|integer|min:30|max:3600',
        ]);
        SiteDeployHook::query()->create([
            'site_id' => $this->site->id,
            'phase' => $this->new_hook_phase,
            'script' => $this->new_hook_script,
            'sort_order' => $this->new_hook_order,
            'timeout_seconds' => $this->new_hook_timeout_seconds,
        ]);
        $this->new_hook_script = '';
        $this->new_hook_order = 0;
        $this->new_hook_timeout_seconds = 900;
        $this->flash_success = 'Deploy hook added.';
        $this->flash_error = null;
    }

    public function deleteDeployHook(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteDeployHook::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->flash_success = 'Hook removed.';
        $this->flash_error = null;
    }

    public function addDeployPipelineStep(): void
    {
        $this->authorize('update', $this->site);
        $types = array_keys(SiteDeployStep::typeLabels());
        $this->validate([
            'new_deploy_step_type' => 'required|string|in:'.implode(',', $types),
            'new_deploy_step_command' => 'nullable|string|max:4000',
            'new_deploy_step_timeout' => 'required|integer|min:30|max:3600',
        ]);
        $needsCustom = in_array($this->new_deploy_step_type, [
            SiteDeployStep::TYPE_NPM_RUN,
            SiteDeployStep::TYPE_CUSTOM,
        ], true);
        if ($needsCustom && trim($this->new_deploy_step_command) === '') {
            $this->addError('new_deploy_step_command', 'This step type needs a value in the command field.');

            return;
        }
        SiteDeployStep::query()->create([
            'site_id' => $this->site->id,
            'sort_order' => (int) ($this->site->deploySteps()->max('sort_order') ?? 0) + 1,
            'step_type' => $this->new_deploy_step_type,
            'custom_command' => trim($this->new_deploy_step_command) !== '' ? trim($this->new_deploy_step_command) : null,
            'timeout_seconds' => $this->new_deploy_step_timeout,
        ]);
        $this->new_deploy_step_command = '';
        $this->new_deploy_step_timeout = 900;
        $this->flash_success = 'Deploy pipeline step added. Runs after git, before the post-deploy command.';
        $this->flash_error = null;
    }

    public function deleteDeployPipelineStep(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteDeployStep::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->flash_success = 'Pipeline step removed.';
        $this->flash_error = null;
    }

    public function moveDeployStepUp(int $id): void
    {
        $this->authorize('update', $this->site);
        $ids = SiteDeployStep::query()->where('site_id', $this->site->id)->orderBy('sort_order')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        if ($pos === false || $pos === 0) {
            return;
        }
        [$ids[$pos - 1], $ids[$pos]] = [$ids[$pos], $ids[$pos - 1]];
        foreach ($ids as $i => $stepId) {
            SiteDeployStep::query()->whereKey($stepId)->update(['sort_order' => $i + 1]);
        }
        $this->flash_success = 'Pipeline order updated.';
        $this->flash_error = null;
    }

    public function moveDeployStepDown(int $id): void
    {
        $this->authorize('update', $this->site);
        $ids = SiteDeployStep::query()->where('site_id', $this->site->id)->orderBy('sort_order')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        if ($pos === false || $pos >= count($ids) - 1) {
            return;
        }
        [$ids[$pos + 1], $ids[$pos]] = [$ids[$pos], $ids[$pos + 1]];
        foreach ($ids as $i => $stepId) {
            SiteDeployStep::query()->whereKey($stepId)->update(['sort_order' => $i + 1]);
        }
        $this->flash_success = 'Pipeline order updated.';
        $this->flash_error = null;
    }

    public function rollbackRelease(int $releaseId, SiteReleaseRollback $rollback): void
    {
        $this->authorize('update', $this->site);
        $this->flash_error = null;
        try {
            $release = SiteRelease::query()->where('site_id', $this->site->id)->findOrFail($releaseId);
            $rollback->rollbackTo($this->site, $release);
            $this->site->refresh();
            $this->flash_success = 'Rolled back active release symlink. Re-install Nginx if document root changed.';
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
        $this->site->load([
            'domains',
            'deployments' => fn ($q) => $q->limit(25),
            'webhookDeliveryLogs' => fn ($q) => $q->limit(30),
            'environmentVariables',
            'redirects',
            'deployHooks',
            'deploySteps',
            'releases' => fn ($q) => $q->orderByDesc('id')->limit(30),
        ]);

        $openSiteInsightsCount = InsightFinding::query()
            ->where('site_id', $this->site->id)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->count();

        return view('livewire.sites.show', [
            'deployHookUrl' => $this->site->deployHookUrl(),
            'openSiteInsightsCount' => $openSiteInsightsCount,
        ]);
    }
}
