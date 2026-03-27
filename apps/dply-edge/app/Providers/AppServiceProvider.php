<?php

namespace App\Providers;

use App\Contracts\DeployEngine;
use App\Services\Deploy\EdgeDeployEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DeployEngine::class, EdgeDeployEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
