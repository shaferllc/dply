<?php

namespace App\Providers;

use App\Contracts\DeployEngine;
use App\Contracts\ServerlessFunctionProvisioner;
use App\Serverless\Stub\AwsLambdaStubProvisioner;
use App\Serverless\Stub\DigitalOceanStubProvisioner;
use App\Serverless\Stub\LocalStubProvisioner;
use App\Services\Deploy\ServerlessDeployEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ServerlessFunctionProvisioner::class, function (): ServerlessFunctionProvisioner {
            return match (config('serverless.provisioner', 'local')) {
                'aws' => new AwsLambdaStubProvisioner,
                'digitalocean' => new DigitalOceanStubProvisioner,
                default => new LocalStubProvisioner,
            };
        });
        $this->app->singleton(DeployEngine::class, ServerlessDeployEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
