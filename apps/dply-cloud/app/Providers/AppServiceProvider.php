<?php

namespace App\Providers;

use App\Contracts\DeployEngine;
use App\Services\Deploy\CloudDeployEngine;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DeployEngine::class, CloudDeployEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::share('dplyMainUrl', (string) config('dply.main_app_url'));
    }
}
