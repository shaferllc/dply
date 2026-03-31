<?php

namespace App\Providers;

use App\Contracts\DeployEngine;
use App\Contracts\HostedWordpressProvisioner;
use App\Services\Deploy\WordpressDeployEngine;
use App\Services\Wordpress\Provisioners\LocalHostedWordpressProvisioner;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(HostedWordpressProvisioner::class, LocalHostedWordpressProvisioner::class);
        $this->app->singleton(DeployEngine::class, WordpressDeployEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::share('dplyMainUrl', (string) config('dply.main_app_url'));
    }
}
