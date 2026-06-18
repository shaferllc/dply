<?php

namespace App\Providers;

use App\Contracts\AwsLambdaGateway;
use App\Events\Servers\ServerAuthorizedKeysSynced;
use App\Jobs\CleanupRemoteSiteArtifactsJob;
use App\Jobs\ProvisionDefaultUserSshKeysToServerJob;
use App\Listeners\ForwardWorkerPoolJobEvent;
use App\Modules\Referrals\Listeners\ProcessReferralInvoicePayment;
use App\Listeners\RecordLivewireDispatchedJob;
use App\Listeners\RecordServerRemoteAccessContext;
use App\Listeners\Servers\DispatchServerAuthorizedKeysSyncedWebhook;
use App\Listeners\SyncBillingOnSubscriptionWebhook;
use App\Listeners\UpdateDispatchedJobLifecycle;
use App\Livewire\Pulse\DatabaseServersCard;
use App\Livewire\Pulse\RedisServersCard;
use App\Livewire\Pulse\WorkerServersCard;
use App\Models\BackupConfiguration;
use App\Models\ImportServerMigration;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\RealtimeApp;
use App\Models\Script;
use App\Models\Server;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Models\SiteProcess;
use App\Models\SiteUptimeMonitor;
use App\Models\StatusPage;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SupervisorProgram;
use App\Models\Team;
use App\Models\User;
use App\Models\UserSshKey;
use App\Models\Workspace;
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use App\Modules\Backups\Observers\BackupAutoResumeObserver;
use App\Modules\Backups\Observers\BackupFailureNotifyObserver;
use App\Modules\Imports\Observers\ImportSiteWakeupObserver;
use App\Modules\Realtime\Observers\RealtimeAppBillingObserver;
use App\Observers\ServerObserver;
use App\Observers\SiteBillingObserver;
use App\Observers\SupervisorProgramObserver;
use App\Observers\TaskRunnerTaskObserver;
use App\Modules\Backups\Policies\BackupConfigurationPolicy;
use App\Modules\Imports\Policies\ImportServerMigrationPolicy;
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
use App\Modules\Certificates\Services\CaddyAutomaticHttpsCertificateEngine;
use App\Modules\Certificates\Services\CertificateEngineResolver;
use App\Modules\Certificates\Services\CertificateRequestService;
use App\Modules\Certificates\Services\CertificateSigningRequestGenerator;
use App\Modules\Certificates\Services\ImportedCertificateInstaller;
use App\Modules\Certificates\Services\LetsEncryptDnsCertificateEngine;
use App\Modules\Certificates\Services\LetsEncryptHttpCertificateEngine;
use App\Modules\Certificates\Services\ZeroSslHttpCertificateEngine;
use App\Services\Deploy\AwsLambdaDeployEngine;
use App\Services\Deploy\ByoServerDeployEngine;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\DigitalOceanFunctionsActionDeployer;
use App\Services\Deploy\DigitalOceanFunctionsDeployEngine;
use App\Services\Deploy\DockerDeployEngine;
use App\Services\Deploy\EphemeralDeployCredentialContext;
use App\Services\Deploy\KubernetesDeployEngine;
use App\Services\Deploy\RuntimeDetection\GitCloner;
use App\Services\Deploy\RuntimeDetection\GoRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\NodeRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\ProcessGitCloner;
use App\Services\Deploy\RuntimeDetection\PythonRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RubyRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RuntimeDetectionEngine;
use App\Services\Deploy\RuntimeDetection\StaticRuntimeDetector;
use App\Services\Deploy\ServerlessProvisionerFactory;
use App\Services\Deploy\SiteResourceBindingResolver;
use App\Modules\Docs\Services\DocsManifest;
use App\Modules\Edge\Services\CloudflareEdgeDelivery;
use App\Modules\Edge\Services\EdgeArtifactPublisher;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Imports\Services\Handlers\HandlerManifest;
use App\Modules\Imports\Services\StepRegistry;
use App\Services\Servers\Bootstrap\DockerHostBootstrapStrategy;
use App\Services\Servers\Bootstrap\KubernetesClusterBootstrapStrategy;
use App\Services\Servers\Bootstrap\ServerBootstrapStrategyResolver;
use App\Services\Servers\Bootstrap\VmServerBootstrapStrategy;
use App\Services\Servers\ServerMetricsGuestScript;
use App\Services\Servers\ServerMetricsRangeQuery;
use App\Services\Servers\ServerWebserverSitesProvider;
use App\Services\Servers\WebserverSwitchPreflight;
use App\Services\Sites\DockerRuntimeSiteProvisioner;
use App\Services\Sites\KubernetesRuntimeSiteProvisioner;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\Sites\SiteApacheProvisioner;
use App\Services\Sites\SiteCaddyProvisioner;
use App\Services\Sites\SiteNginxProvisioner;
use App\Services\Sites\SiteOpenLiteSpeedProvisioner;
use App\Services\Sites\SiteRuntimeProvisionerRegistry;
use App\Services\Sites\SiteSystemdUnitBuilder;
use App\Services\Sites\SiteTraefikProvisioner;
use App\Services\Sites\SiteWebserverProvisionerRegistry;
use App\Services\Sites\TestingHostnameProvisioner;
use App\Services\Sites\UptimeProbeRegionResolver;
use App\Services\Sites\UptimeProbeWorkerResolver;
use App\Services\Sites\WebserverConfig\ApacheWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\CaddyWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\NginxWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\OpenLiteSpeedWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\TraefikWebserverConfigEngine;
use App\Services\Sites\WebserverConfig\WebserverConfigEngineRegistry;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use App\Services\WordPress\Advisories\AdvisoryProvider;
use App\Services\WordPress\Advisories\WordfenceIntelligenceProvider;
use App\Support\Debug\SshCallRecorder;
use App\Support\Debug\SshCallsCollector;
use App\Support\Debug\TaskRunnerBroadcastBridge;
use App\Modules\Edge\Support\EdgeFilesystemRegistrar;
use App\Modules\Edge\Support\EdgePlatformCredentials;
use App\Support\Servers\EnvoyAdminScript;
use App\Support\Servers\ServerConsoleActionLookup;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Livewire\Blaze\Blaze;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // WordPress advisory feed (Q20 — Wordfence Intelligence default).
        // Singleton because it caches per-request lookups in process.
        $this->app->singleton(AdvisoryProvider::class, WordfenceIntelligenceProvider::class);

        // Scoped (request-singleton, Octane-safe): the resolver memoizes its expensive
        // per-site count queries on the instance, and is hit twice per render
        // (DeploymentContractBuilder::build + DeploymentPreflightValidator::validate).
        $this->app->scoped(SiteResourceBindingResolver::class);

        // Scoped: the engine-overview panel renders one chart card per active
        // engine (e.g. caddy backend + traefik edge), and the range fetch +
        // latest-snapshot select would otherwise run once per engine instance.
        // The class memoizes snapshots per (server, range) on the instance.
        $this->app->scoped(ServerMetricsRangeQuery::class);

        // Scoped: the webserver picker calls plan()/isBlocked() once per known
        // target during a single render, and each non-cached target triggers
        // its own varnish-running select. The instance already memoizes plan()
        // results — we add an explicit binding to make sure every call site
        // hits the same instance.
        $this->app->scoped(WebserverSwitchPreflight::class);

        // Scoped: the drift detector and switch preflight both run on a single
        // render of the webserver workspace page and each used to load the
        // server's sites (+ config profiles + certificates) independently. A
        // shared, request-memoized loader collapses those into one query set.
        $this->app->scoped(ServerWebserverSitesProvider::class);
        $this->app->scoped(ServerConsoleActionLookup::class);

        // Scoped: the contextual docs sidebar renders on EVERY authenticated page
        // and resolves a title/url per published doc (indexEntries), each of which
        // calls DocsManifest::find(). Locally the persistent cache is bypassed so
        // edits show up, so without one shared request-scoped instance (which
        // memoizes the parsed manifest) every find() re-globbed + re-parsed all
        // ~130 docs/*.md — O(n²) file I/O that added ~3.5s to every page.
        $this->app->scoped(DocsManifest::class);

        // Scoped: queue jobs may override SSH private key for one deploy via
        // EphemeralDeployCredentialManager without touching the server key.
        $this->app->scoped(EphemeralDeployCredentialContext::class);

        // Migration step handler registry — bind handler classes to their step keys.
        // Bind eagerly so the orchestrator always has a fully populated registry; the
        // resolved handler instances are still container-managed (per-resolve).
        $this->app->singleton(StepRegistry::class, function (): StepRegistry {
            $registry = new StepRegistry;
            foreach (HandlerManifest::all() as $handlerClass) {
                $registry->register($handlerClass::key(), $handlerClass);
            }

            return $registry;
        });

        // Scoped (reset per request/job) so its per-instance identity memo
        // dedupes the repeated social_accounts/git_provider_tokens lookups a
        // single site render fans out, without caching stale models across
        // jobs in a long-lived queue worker.
        $this->app->scoped(GitIdentityResolver::class);

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
            // Caddy fronts manage TLS themselves (automatic HTTPS) — intercept
            // before the certbot engine so Caddy sites never shell out to certbot.
            CaddyAutomaticHttpsCertificateEngine::class,
            LetsEncryptHttpCertificateEngine::class,
            LetsEncryptDnsCertificateEngine::class,
            ZeroSslHttpCertificateEngine::class,
            ImportedCertificateInstaller::class,
            CertificateSigningRequestGenerator::class,
        ], 'site.certificate.engines');

        $this->app->singleton(RuntimeDetectionEngine::class, function ($app) {
            return new RuntimeDetectionEngine($app->tagged('site.runtime.detectors'));
        });

        $this->app->tag([
            PhpRuntimeDetector::class,
            NodeRuntimeDetector::class,
            PythonRuntimeDetector::class,
            RubyRuntimeDetector::class,
            GoRuntimeDetector::class,
            StaticRuntimeDetector::class,
        ], 'site.runtime.detectors');

        $this->app->bind(GitCloner::class, ProcessGitCloner::class);

        $this->app->singleton(EdgeArtifactPublisher::class);
        $this->app->singleton(EdgeHostMapPublisher::class);
        $this->app->singleton(EdgeDeliveryContextResolver::class);
        $this->app->singleton(CloudflareEdgeDelivery::class);
        $this->app->singleton(EdgeFilesystemRegistrar::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blaze::optimize()
            ->in(resource_path('views/components/spinner.blade.php'), memo: true)
            ->in(resource_path('views/components/application-logo.blade.php'), memo: true)
            ->in(resource_path('views/components/input-error.blade.php'), memo: true)
            ->in(resource_path('views/components/oauth-provider-icon.blade.php'), memo: true)
            ->in(resource_path('views/components/credentials-provider-icon.blade.php'), memo: true);

        $this->registerCustomPulseCards();

        $this->registerEdgeR2FilesystemDisk();

        $this->discardCorruptedViteHotFile();

        $this->mergeServerMonitoringInstallScript();

        $this->mergeEnvoyServiceActionScripts();

        Cashier::useCustomerModel(Organization::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);

        Event::listen(WebhookReceived::class, ProcessReferralInvoicePayment::class);
        Event::listen(WebhookReceived::class, SyncBillingOnSubscriptionWebhook::class);
        Event::listen(ServerAuthorizedKeysSynced::class, DispatchServerAuthorizedKeysSyncedWebhook::class);

        // Mirror Livewire-dispatched queue jobs into task_runner_tasks so the
        // bottom debug panel surfaces "what's running for me right now".
        Event::listen(JobQueued::class, [RecordLivewireDispatchedJob::class, 'handle']);
        Event::listen(JobProcessing::class, [UpdateDispatchedJobLifecycle::class, 'handleProcessing']);
        Event::listen(JobProcessed::class, [UpdateDispatchedJobLifecycle::class, 'handleProcessed']);
        Event::listen(JobFailed::class, [UpdateDispatchedJobLifecycle::class, 'handleFailed']);
        Event::listen(JobProcessing::class, [RecordServerRemoteAccessContext::class, 'handleProcessing']);
        Event::listen(JobProcessed::class, [RecordServerRemoteAccessContext::class, 'handleProcessed']);
        Event::listen(JobFailed::class, [RecordServerRemoteAccessContext::class, 'handleFailed']);

        // Box-side worker-pool agent: forward per-job events to dply for the live
        // dashboard. No-op unless DPLY_POOL_EVENT_URL/_TOKEN are set on the box.
        Event::listen(JobProcessing::class, [ForwardWorkerPoolJobEvent::class, 'handleProcessing']);
        Event::listen(JobProcessed::class, [ForwardWorkerPoolJobEvent::class, 'handleProcessed']);
        Event::listen(JobFailed::class, [ForwardWorkerPoolJobEvent::class, 'handleFailed']);

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
        Gate::policy(ImportServerMigration::class, ImportServerMigrationPolicy::class);

        Gate::define('manageNotificationChannels', function (User $user, User|Organization|Team $owner): bool {
            if ($owner instanceof User) {
                return $user->id === $owner->id;
            }
            if ($owner instanceof Organization) {
                return $owner->hasAdminAccess($user);
            }

            return $owner->userCanManageNotificationChannels($user);
        });

        Gate::define('viewNotificationChannels', function (User $user, User|Organization|Team $owner): bool {
            if ($owner instanceof User) {
                return $user->id === $owner->id;
            }
            if ($owner instanceof Organization) {
                return $owner->hasAdminAccess($user);
            }

            return $owner->organization->hasMember($user) && $owner->hasMember($user);
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

        /*
         * Bridge TaskRunner StreamingLogger events to the org-scoped Reverb
         * channel so the global TaskRunner debug panel (platform admins) can
         * tail every SSH/SCP/Process invocation in real time. Deferred to
         * booted() so the package's TaskServiceProvider has finished wiring
         * its singleton before we attach.
         */
        $this->app->booted(function (): void {
            TaskRunnerBroadcastBridge::register(
                $this->app->make(StreamingLoggerInterface::class)
            );
        });

        $this->app->booted(fn () => $this->registerSshDebugbarCollector());

        Server::observe(ServerObserver::class);
        Site::observe(ImportSiteWakeupObserver::class);
        Site::observe(SiteBillingObserver::class);
        RealtimeApp::observe(RealtimeAppBillingObserver::class);
        SupervisorProgram::observe(SupervisorProgramObserver::class);
        TaskRunnerTask::observe(TaskRunnerTaskObserver::class);
        ServerDatabaseBackup::observe(BackupAutoResumeObserver::class);
        SiteFileBackup::observe(BackupAutoResumeObserver::class);
        ServerDatabaseBackup::observe(BackupFailureNotifyObserver::class);
        SiteFileBackup::observe(BackupFailureNotifyObserver::class);

        Server::created(function (Server $server): void {
            if ($server->status === Server::STATUS_READY && ! empty($server->ssh_private_key)) {
                ProvisionDefaultUserSshKeysToServerJob::dispatch($server->id);
            }
        });

        Site::created(function (Site $site): void {
            rescue(
                function () use ($site): void {
                    $regions = array_keys((array) config('site_uptime.probe_regions', []));
                    if ($regions === []) {
                        return;
                    }

                    // Probe from the worker nearest the host; the cosmetic
                    // region label is derived from that worker (falling back to
                    // the host's nearest region when no worker is configured).
                    $worker = app(UptimeProbeWorkerResolver::class)->forSite($site);
                    $region = app(UptimeProbeWorkerResolver::class)->regionFor($worker)
                        ?? app(UptimeProbeRegionResolver::class)->forSite($site);

                    SiteUptimeMonitor::query()->firstOrCreate(
                        ['site_id' => $site->id, 'sort_order' => 0],
                        [
                            'label' => __('Homepage check'),
                            'path' => null,
                            'probe_region' => $region,
                            'probe_worker' => $worker,
                        ],
                    );
                },
                report: false,
            );

            $server = $site->server;
            if ($server === null) {
                return;
            }
            rescue(
                fn () => app(OutboundWebhookDispatcher::class)->dispatchForServer(
                    'site.created',
                    $server,
                    [
                        'site' => [
                            'id' => $site->id,
                            'name' => $site->name,
                            'primary_domain' => $site->primaryDomain()?->hostname,
                            'webserver' => $site->webserver(),
                            'application_type' => $site->type->value,
                        ],
                    ],
                    'Site '.$site->name.' created'
                ),
                report: false,
            );
        });

        Site::deleted(function (Site $site): void {
            $server = $site->server;
            if ($server === null) {
                return;
            }
            rescue(
                fn () => app(OutboundWebhookDispatcher::class)->dispatchForServer(
                    'site.deleted',
                    $server,
                    [
                        'site' => [
                            'id' => $site->id,
                            'name' => $site->name,
                        ],
                    ],
                    'Site '.$site->name.' deleted'
                ),
                report: false,
            );
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
            // Remove the managed preview/testing DNS record at the provider
            // BEFORE dropping the previewDomains rows — the teardown reads the
            // hostname/zone/record id off those rows, so deleting them first
            // would orphan the live DNS record (and a re-created same-slug site
            // would inherit a stale A record pointing at the old box).
            rescue(
                fn () => app(TestingHostnameProvisioner::class)->delete($site),
                report: false,
            );
            $site->previewDomains()->delete();
            if ($site->server?->isDigitalOceanFunctionsHost()) {
                rescue(
                    fn () => app(DigitalOceanFunctionsActionDeployer::class)->delete($site),
                    report: false,
                );
            } elseif ($site->server?->hostCapabilities()->supportsFunctionDeploy()) {
                // Non-DO serverless targets do not have remote SSH artifacts to clean up here.
            } else {
                // Compute systemd unit names from the live site so the
                // cleanup job (which runs after the row is gone) can
                // disable + remove them. Empty for PHP/static sites —
                // SiteSystemdProvisioner only manages units for
                // long-running non-PHP runtimes.
                $unitBuilder = app(SiteSystemdUnitBuilder::class);
                $systemdUnitNames = [];
                $runtimeKey = $site->runtimeKey();
                if ($runtimeKey !== null && $runtimeKey !== 'php' && $runtimeKey !== 'static') {
                    $systemdUnitNames[] = $unitBuilder->webUnitName($site);
                    $site->loadMissing('processes');
                    foreach ($site->processes as $process) {
                        if ($process->type === SiteProcess::TYPE_WEB) {
                            continue;
                        }
                        $systemdUnitNames[] = $unitBuilder->processUnitName($site, $process);
                    }
                }

                CleanupRemoteSiteArtifactsJob::dispatch([
                    'server_id' => $site->server_id,
                    'webserver' => $site->webserver(),
                    'nginx_basename' => $site->webserverConfigBasename(),
                    'php_fpm_pool_name' => $site->usesDedicatedPhpFpmPool() ? $site->phpFpmPoolName() : null,
                    'repository_base' => rtrim($site->effectiveRepositoryPath(), '/'),
                    'deploy_strategy' => $site->deploy_strategy ?? 'simple',
                    'primary_hostname' => $primary?->hostname,
                    'ssl_was_active' => $site->ssl_status === Site::SSL_ACTIVE,
                    'supervisor_program_ids' => $svIds,
                    'site_id' => $site->id,
                    'systemd_unit_names' => $systemdUnitNames,
                ]);
            }
            SupervisorProgram::query()->where('site_id', $site->id)->delete();
        });

        RateLimiter::for('api', function (Request $request) {
            $token = $request->attributes->get('api_token');

            return Limit::perMinute(60)->by($token ? 'api:'.$token->id : $request->ip());
        });

        // Edge surface gets a higher ceiling — log tailing + ad-hoc
        // deploys are chatty by design, and a typical CI run can fire
        // 20–30 calls in quick succession (lint + deploy + poll). Keyed
        // by token id so one chatty token can't starve another's
        // budget. Falls back to IP when called pre-auth (shouldn't
        // happen post-`auth.api` but defensive).
        RateLimiter::for('edge-api', function (Request $request) {
            $token = $request->attributes->get('api_token');

            return Limit::perMinute(600)->by($token ? 'edge-api:'.$token->id : 'edge-api-ip:'.$request->ip());
        });

        RateLimiter::for('site-webhook', function (Request $request) {
            $site = $request->route('site');
            $key = $site instanceof Site ? 'wh:'.$site->id : 'wh-ip:'.$request->ip();

            return Limit::perMinute((int) config('sites.webhook_max_attempts_per_minute', 30))->by($key);
        });

        RateLimiter::for('metrics-ingest', function (Request $request) {
            return Limit::perMinute(300)->by($request->ip());
        });

        // Per-request log POSTs from deployed serverless functions. Keyed by
        // site so one busy function can't starve another; generous because a
        // function fires this once per request it serves. Over the limit the
        // handler's fire-and-forget POST just 429s and the row is dropped.
        RateLimiter::for('function-log-ingest', function (Request $request) {
            $site = $request->route('site');
            $key = $site instanceof Site ? 'fli:'.$site->id : 'fli-ip:'.$request->ip();

            return Limit::perMinute((int) config('sites.function_log_ingest_per_minute', 1000))->by($key);
        });

        RateLimiter::for('metrics-guest-push', function (Request $request) {
            $sid = $request->input('server_id');

            return Limit::perMinute(120)->by(is_string($sid) && $sid !== '' ? 'gmp:'.$sid : 'gmp-ip:'.$request->ip());
        });
    }

    /**
     * Add an "SSH" tab to Debugbar that lists every inline SSH call the current
     * page made (one timeline bar per command). Only wired when Debugbar is
     * enabled, so the request-scoped recorder is never bound in queue workers
     * or production — keeping {@see SshCallRecorder} from leaking in
     * long-lived processes. Most dply SSH is queued and runs out-of-band, so
     * this captures inline reads only (e.g. config-file fetches).
     */
    private function registerSshDebugbarCollector(): void
    {
        if (! $this->app->bound('debugbar')) {
            return;
        }

        $debugbar = $this->app->make('debugbar');

        if (! $debugbar->isEnabled()) {
            return;
        }

        $this->app->instance(SshCallRecorder::class, new SshCallRecorder);

        $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        try {
            $debugbar->addCollector(new SshCallsCollector(
                $this->app->make(SshCallRecorder::class),
                $start,
            ));
        } catch (\Throwable) {
            // Collector already added (e.g. on a re-resolved container) — ignore.
        }
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
     * Envoy start/restart must free :80 and wait for admin :9901 — a bare
     * systemctl restart leaves the unit crash-looping when :80 is taken.
     */
    private function mergeEnvoyServiceActionScripts(): void
    {
        $script = 'sudo -n bash -lc '.escapeshellarg(EnvoyAdminScript::startServiceScript());
        $flags = [
            'script' => $script,
            'refresh_webserver_live_state_after_finish' => true,
            'rerun_probe_after_finish' => true,
        ];

        foreach (['start_envoy', 'restart_envoy', 'reload_envoy'] as $key) {
            config([
                "server_manage.service_actions.{$key}" => array_merge(
                    (array) config("server_manage.service_actions.{$key}", []),
                    $flags,
                ),
            ]);
        }

        config([
            'server_manage.service_actions.start_envoy.description' => 'Stop competing edge/primary webservers, move Caddy off :80 (backend ports only), validate envoy.yaml, start envoy, and wait for admin :9901.',
            'server_manage.service_actions.restart_envoy.description' => 'Safe Envoy restart: frees :80 (including legacy Caddy front configs), validates config, waits for admin :9901.',
            'server_manage.service_actions.reload_envoy.description' => 'Safe Envoy reload (restart): frees :80 (including legacy Caddy front configs), validates config, waits for admin :9901.',
        ]);
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

    /**
     * Register the per-service Pulse cards (Redis / Database / Workers) that
     * surface dply's centrally-collected server metrics on the Pulse dashboard.
     */
    private function registerCustomPulseCards(): void
    {
        Livewire::component('pulse.redis-servers', RedisServersCard::class);
        Livewire::component('pulse.database-servers', DatabaseServersCard::class);
        Livewire::component('pulse.worker-servers', WorkerServersCard::class);
    }

    private function registerEdgeR2FilesystemDisk(): void
    {
        $cfg = config('edge.r2');
        $bucket = is_string($cfg['bucket'] ?? null) ? trim($cfg['bucket']) : '';
        if ($bucket === '') {
            return;
        }

        config([
            'filesystems.disks.edge_r2' => [
                'driver' => 's3',
                'key' => $cfg['key'],
                'secret' => $cfg['secret'],
                'region' => $cfg['region'],
                'bucket' => $bucket,
                'endpoint' => EdgePlatformCredentials::r2Endpoint(),
                'use_path_style_endpoint' => $cfg['use_path_style_endpoint'],
                'throw' => false,
                'report' => false,
            ],
        ]);
    }
}
