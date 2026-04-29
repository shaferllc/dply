<?php

namespace App\Providers;

use App\Contracts\AwsLambdaGateway;
use App\Events\Servers\ServerAuthorizedKeysSynced;
use App\Jobs\CleanupRemoteSiteArtifactsJob;
use App\Jobs\ProvisionDefaultUserSshKeysToServerJob;
use App\Listeners\ProcessReferralInvoicePayment;
use App\Listeners\Servers\DispatchServerAuthorizedKeysSyncedWebhook;
use App\Models\BackupConfiguration;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Script;
use App\Models\Server;
use App\Models\Site;
use App\Models\StatusPage;
use App\Models\SupervisorProgram;
use App\Models\Team;
use App\Models\User;
use App\Models\UserSshKey;
use App\Models\Workspace;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use App\Observers\ServerObserver;
use App\Observers\SupervisorProgramObserver;
use App\Observers\TaskRunnerTaskObserver;
use App\Policies\BackupConfigurationPolicy;
use App\Policies\IncidentPolicy;
use App\Policies\NotificationChannelPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\ProviderCredentialPolicy;
use App\Policies\ScriptPolicy;
use App\Policies\ServerPolicy;
use App\Policies\SitePolicy;
use App\Policies\StatusPagePolicy;
use App\Policies\TeamPolicy;
use App\Policies\UserSshKeyPolicy;
use App\Policies\WorkspacePolicy;
use App\Services\Certificates\CertificateEngineResolver;
use App\Services\Certificates\CertificateRequestService;
use App\Services\Certificates\CertificateSigningRequestGenerator;
use App\Services\Certificates\ImportedCertificateInstaller;
use App\Services\Certificates\LetsEncryptDnsCertificateEngine;
use App\Services\Certificates\LetsEncryptHttpCertificateEngine;
use App\Services\Certificates\ZeroSslHttpCertificateEngine;
use App\Services\Deploy\AwsLambdaDeployEngine;
use App\Services\Deploy\ByoServerDeployEngine;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\DigitalOceanFunctionsActionDeployer;
use App\Services\Deploy\DigitalOceanFunctionsDeployEngine;
use App\Services\Deploy\DockerDeployEngine;
use App\Services\Deploy\KubernetesDeployEngine;
use App\Services\Deploy\ServerlessProvisionerFactory;
use App\Services\Servers\Bootstrap\DockerHostBootstrapStrategy;
use App\Services\Servers\Bootstrap\KubernetesClusterBootstrapStrategy;
use App\Services\Servers\Bootstrap\ServerBootstrapStrategyResolver;
use App\Services\Servers\Bootstrap\VmServerBootstrapStrategy;
use App\Services\Servers\ServerMetricsGuestScript;
use App\Services\Sites\DockerRuntimeSiteProvisioner;
use App\Services\Sites\KubernetesRuntimeSiteProvisioner;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\Sites\SiteApacheProvisioner;
use App\Services\Sites\SiteCaddyProvisioner;
use App\Services\Sites\SiteNginxProvisioner;
use App\Services\Sites\SiteOpenLiteSpeedProvisioner;
use App\Services\Sites\SiteRuntimeProvisionerRegistry;
use App\Services\Sites\SiteTraefikProvisioner;
use App\Services\Sites\SiteWebserverProvisionerRegistry;
use App\Services\Sites\WebserverConfig\ApacheWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\CaddyWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\NginxWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\OpenLiteSpeedWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\TraefikWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\WebserverConfigEngineRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ByoServerDeployEngine::class);
        $this->app->singleton(AwsLambdaGateway::class, fn () => ServerlessProvisionerFactory::defaultAwsGateway());
        $this->app->singleton(ServerlessProvisionerFactory::class);
        $this->app->singleton(CertificateEngineResolver::class, function ($app) {
            return new CertificateEngineResolver($app->tagged('site.certificate.engines'));
        });
        $this->app->singleton(CertificateRequestService::class);
        $this->app->singleton(DeployEngineResolver::class, function ($app) {
            return new DeployEngineResolver(
                $app->make(ByoServerDeployEngine::class),
                $app->make(DigitalOceanFunctionsDeployEngine::class),
                $app->make(AwsLambdaDeployEngine::class),
                $app->make(DockerDeployEngine::class),
                $app->make(KubernetesDeployEngine::class),
            );
        });

        $this->app->singleton(ServerBootstrapStrategyResolver::class, function ($app) {
            return new ServerBootstrapStrategyResolver($app->tagged('server.bootstrap.strategies'));
        });

        $this->app->tag([
            VmServerBootstrapStrategy::class,
            DockerHostBootstrapStrategy::class,
            KubernetesClusterBootstrapStrategy::class,
        ], 'server.bootstrap.strategies');

        $this->app->singleton(SiteWebserverProvisionerRegistry::class, function ($app) {
            return new SiteWebserverProvisionerRegistry($app->tagged('site.webserver.provisioners'));
        });

        $this->app->singleton(WebserverConfigEngineRegistry::class, function ($app) {
            return new WebserverConfigEngineRegistry($app->tagged('site.webserver.config.engines'));
        });

        $this->app->tag([
            NginxWebserverConfigEngine::class,
            ApacheWebserverConfigEngine::class,
            CaddyWebserverConfigEngine::class,
            TraefikWebserverConfigEngine::class,
            OpenLiteSpeedWebserverConfigEngine::class,
        ], 'site.webserver.config.engines');

        $this->app->singleton(SiteRuntimeProvisionerRegistry::class, function ($app) {
            return new SiteRuntimeProvisionerRegistry($app->tagged('site.runtime.provisioners'));
        });

        $this->app->tag([
            SiteNginxProvisioner::class,
            SiteCaddyProvisioner::class,
            SiteApacheProvisioner::class,
            SiteOpenLiteSpeedProvisioner::class,
            SiteTraefikProvisioner::class,
        ], 'site.webserver.provisioners');

        $this->app->tag([
            DockerRuntimeSiteProvisioner::class,
            KubernetesRuntimeSiteProvisioner::class,
        ], 'site.runtime.provisioners');

        $this->app->tag([
            LetsEncryptHttpCertificateEngine::class,
            LetsEncryptDnsCertificateEngine::class,
            ZeroSslHttpCertificateEngine::class,
            ImportedCertificateInstaller::class,
            CertificateSigningRequestGenerator::class,
        ], 'site.certificate.engines');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->discardCorruptedViteHotFile();

        $this->mergeServerMonitoringInstallScript();

        Cashier::useCustomerModel(Organization::class);

        Event::listen(WebhookReceived::class, ProcessReferralInvoicePayment::class);
        Event::listen(ServerAuthorizedKeysSynced::class, DispatchServerAuthorizedKeysSyncedWebhook::class);

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(ProviderCredential::class, ProviderCredentialPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(UserSshKey::class, UserSshKeyPolicy::class);
        Gate::policy(NotificationChannel::class, NotificationChannelPolicy::class);
        Gate::policy(BackupConfiguration::class, BackupConfigurationPolicy::class);
        Gate::policy(Script::class, ScriptPolicy::class);
        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(StatusPage::class, StatusPagePolicy::class);
        Gate::policy(Incident::class, IncidentPolicy::class);

        Gate::define('manageNotificationChannels', function (User $user, User|Organization|Team $owner): bool {
            return match (true) {
                $owner instanceof User => $user->id === $owner->id,
                $owner instanceof Organization => $owner->hasAdminAccess($user),
                $owner instanceof Team => $owner->userCanManageNotificationChannels($user),
                default => false,
            };
        });

        Gate::define('viewNotificationChannels', function (User $user, User|Organization|Team $owner): bool {
            return match (true) {
                $owner instanceof User => $user->id === $owner->id,
                $owner instanceof Organization => $owner->hasAdminAccess($user),
                $owner instanceof Team => $owner->organization->hasMember($user) && $owner->hasMember($user),
                default => false,
            };
        });

        Gate::define('viewPlatformAdmin', function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            $raw = (string) config('admin.allowed_emails', '');
            $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));

            if ($allowed === []) {
                return false;
            }

            return in_array($user->email, $allowed, true);
        });

        /*
         * Laravel Pulse registers viewPulse as local-only; override after all providers so
         * platform admins match Horizon /admin access (see config/admin.php).
         */
        $this->app->booted(function (): void {
            Gate::define('viewPulse', function (?User $user): bool {
                return $user !== null && Gate::forUser($user)->allows('viewPlatformAdmin');
            });
        });

        Server::observe(ServerObserver::class);
        SupervisorProgram::observe(SupervisorProgramObserver::class);
        TaskRunnerTask::observe(TaskRunnerTaskObserver::class);

        Server::created(function (Server $server): void {
            if ($server->status === Server::STATUS_READY && ! empty($server->ssh_private_key)) {
                ProvisionDefaultUserSshKeysToServerJob::dispatch($server->id);
            }
        });

        Server::updated(function (Server $server): void {
            if ($server->wasChanged('status') && $server->status === Server::STATUS_READY && ! empty($server->ssh_private_key)) {
                ProvisionDefaultUserSshKeysToServerJob::dispatch($server->id);
            }
        });

        Site::deleting(function (Site $site): void {
            rescue(
                fn () => app(RepositoryWebhookProvisioner::class)->disable($site),
                report: false,
            );
            $site->loadMissing(['certificates', 'previewDomains']);
            $primary = $site->primaryDomain();
            $svIds = SupervisorProgram::query()->where('site_id', $site->id)->pluck('id')->all();
            foreach ($site->certificates as $certificate) {
                rescue(
                    fn () => app(CertificateRequestService::class)->removeArtifacts($certificate),
                    report: false,
                );
            }
            $site->previewDomains()->delete();
            if ($site->server?->isDigitalOceanFunctionsHost()) {
                rescue(
                    fn () => app(DigitalOceanFunctionsActionDeployer::class)->delete($site),
                    report: false,
                );
            } elseif ($site->server?->hostCapabilities()->supportsFunctionDeploy()) {
                // Non-DO serverless targets do not have remote SSH artifacts to clean up here.
            } else {
                CleanupRemoteSiteArtifactsJob::dispatch([
                    'server_id' => $site->server_id,
                    'webserver' => $site->webserver(),
                    'nginx_basename' => $site->nginxConfigBasename(),
                    'repository_base' => rtrim($site->effectiveRepositoryPath(), '/'),
                    'deploy_strategy' => $site->deploy_strategy ?? 'simple',
                    'primary_hostname' => $primary?->hostname,
                    'ssl_was_active' => $site->ssl_status === Site::SSL_ACTIVE,
                    'supervisor_program_ids' => $svIds,
                    'site_id' => $site->id,
                ]);
            }
            SupervisorProgram::query()->where('site_id', $site->id)->delete();
        });

        RateLimiter::for('api', function (Request $request) {
            $token = $request->attributes->get('api_token');

            return Limit::perMinute(60)->by($token ? 'api:'.$token->id : $request->ip());
        });

        RateLimiter::for('site-webhook', function (Request $request) {
            $site = $request->route('site');
            $key = $site instanceof Site ? 'wh:'.$site->id : 'wh-ip:'.$request->ip();

            return Limit::perMinute((int) config('sites.webhook_max_attempts_per_minute', 30))->by($key);
        });

        RateLimiter::for('metrics-ingest', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });

        RateLimiter::for('metrics-guest-push', function (Request $request) {
            $sid = $request->input('server_id');

            return Limit::perMinute(120)->by(is_string($sid) && $sid !== '' ? 'gmp:'.$sid : 'gmp-ip:'.$request->ip());
        });
    }

    /**
     * Replaces the fallback apt-only script with apt + deploy of resources/server-scripts/server-metrics-snapshot.py.
     */
    private function mergeServerMonitoringInstallScript(): void
    {
        try {
            $guest = $this->app->make(ServerMetricsGuestScript::class);
            if (! is_readable($guest->localPath())) {
                return;
            }
            config([
                'server_services.install_actions.install_monitoring_prerequisites.script' => $guest->monitoringPrerequisitesInstallScript(),
            ]);
        } catch (\Throwable) {
            // Keep config/server_services.php fallback when the guest file is unavailable.
        }
    }

    /**
     * Remove public/hot when its contents are not a plain http(s) dev-server URL.
     * Pasting colored terminal output into hot (or .env) produces garbage; the browser then
     * resolves asset URLs relative to the app origin and requests fail with mangled paths.
     */
    private function discardCorruptedViteHotFile(): void
    {
        $path = public_path('hot');

        if (! is_file($path)) {
            return;
        }

        $line = trim((string) file_get_contents($path));

        if ($line === '' || ! preg_match('/\Ahttps?:\/\//i', $line)) {
            @unlink($path);
        }
    }
}
