<?php

namespace App\Providers;

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
use App\Services\Deploy\ByoServerDeployEngine;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Servers\ServerMetricsGuestScript;
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
        $this->app->singleton(DeployEngineResolver::class, function ($app) {
            return new DeployEngineResolver($app->make(ByoServerDeployEngine::class));
        });
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
            $primary = $site->primaryDomain();
            $svIds = SupervisorProgram::query()->where('site_id', $site->id)->pluck('id')->all();
            CleanupRemoteSiteArtifactsJob::dispatch([
                'server_id' => $site->server_id,
                'nginx_basename' => $site->nginxConfigBasename(),
                'repository_base' => rtrim($site->effectiveRepositoryPath(), '/'),
                'deploy_strategy' => $site->deploy_strategy ?? 'simple',
                'primary_hostname' => $primary?->hostname,
                'ssl_was_active' => $site->ssl_status === Site::SSL_ACTIVE,
                'supervisor_program_ids' => $svIds,
                'site_id' => $site->id,
            ]);
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
