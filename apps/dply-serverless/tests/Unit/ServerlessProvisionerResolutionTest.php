<?php

namespace Tests\Unit;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Serverless\Stub\AwsLambdaStubProvisioner;
use App\Serverless\Stub\DigitalOceanStubProvisioner;
use App\Serverless\Stub\LocalStubProvisioner;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ServerlessProvisionerResolutionTest extends TestCase
{
    public function test_unknown_provisioner_falls_back_to_local_stub(): void
    {
        Config::set('serverless.provisioner', 'unknown-provider');

        $provisioner = $this->app->make(ServerlessFunctionProvisioner::class);

        $this->assertInstanceOf(LocalStubProvisioner::class, $provisioner);
    }

    public function test_aws_driver_resolves_aws_stub(): void
    {
        Config::set('serverless.provisioner', 'aws');

        $this->assertInstanceOf(AwsLambdaStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_digitalocean_driver_resolves_digitalocean_stub(): void
    {
        Config::set('serverless.provisioner', 'digitalocean');

        $this->assertInstanceOf(DigitalOceanStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }
}
