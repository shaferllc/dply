<?php

namespace App\Providers;

use App\Jobs\CleanupRemoteSiteArtifactsJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Models\Team;
use App\Policies\OrganizationPolicy;
use App\Policies\ProviderCredentialPolicy;
use App\Policies\ServerPolicy;
use App\Policies\SitePolicy;
use App\Policies\TeamPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Cashier::useCustomerModel(Organization::class);

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(ProviderCredential::class, ProviderCredentialPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);

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

    }
}
