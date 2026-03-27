<?php

namespace Tests\Unit;

use App\Contracts\ServerlessFunctionProvisioner;
use App\Serverless\Aws\AwsLambdaSdkProvisioner;
use App\Serverless\Cloudflare\CloudflareWorkersProvisioner;
use App\Serverless\DigitalOcean\DigitalOceanOpenWhiskActionProvisioner;
use App\Serverless\Netlify\NetlifyZipDeployProvisioner;
use App\Serverless\Stub\AwsLambdaStubProvisioner;
use App\Serverless\Stub\DigitalOceanStubProvisioner;
use App\Serverless\Stub\LocalStubProvisioner;
use App\Serverless\Stub\RoadmapStubProvisioner;
use App\Serverless\Vercel\VercelZipDeployProvisioner;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
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
        Config::set('serverless.aws.use_real_sdk', false);

        $this->assertInstanceOf(AwsLambdaStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_aws_driver_with_real_sdk_flag_resolves_sdk_provisioner(): void
    {
        Config::set('serverless.provisioner', 'aws');
        Config::set('serverless.aws.use_real_sdk', true);

        $this->assertInstanceOf(AwsLambdaSdkProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_digitalocean_driver_resolves_digitalocean_stub(): void
    {
        Config::set('serverless.provisioner', 'digitalocean');

        $this->assertInstanceOf(DigitalOceanStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_digitalocean_driver_uses_openwhisk_when_fully_configured(): void
    {
        Config::set('serverless.provisioner', 'digitalocean');
        Config::set('serverless.digitalocean.use_real_api', true);
        Config::set('serverless.digitalocean.api_host', 'https://faas.test');
        Config::set('serverless.digitalocean.namespace', 'ns');
        Config::set('serverless.digitalocean.access_key', 'dof_v1_x:y');
        Config::set('serverless.digitalocean.zip_path_prefix', sys_get_temp_dir());

        $this->assertInstanceOf(DigitalOceanOpenWhiskActionProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_digitalocean_driver_falls_back_to_stub_when_zip_prefix_missing(): void
    {
        Config::set('serverless.provisioner', 'digitalocean');
        Config::set('serverless.digitalocean.use_real_api', true);
        Config::set('serverless.digitalocean.api_host', 'https://faas.test');
        Config::set('serverless.digitalocean.namespace', 'ns');
        Config::set('serverless.digitalocean.access_key', 'dof_v1_x:y');
        Config::set('serverless.digitalocean.zip_path_prefix', null);

        $this->assertInstanceOf(DigitalOceanStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_digitalocean_driver_falls_back_to_stub_when_access_key_missing(): void
    {
        Config::set('serverless.provisioner', 'digitalocean');
        Config::set('serverless.digitalocean.use_real_api', true);
        Config::set('serverless.digitalocean.api_host', 'https://faas.test');
        Config::set('serverless.digitalocean.namespace', 'ns');
        Config::set('serverless.digitalocean.access_key', '');
        Config::set('serverless.digitalocean.zip_path_prefix', sys_get_temp_dir());

        $this->assertInstanceOf(DigitalOceanStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_cloudflare_driver_uses_stub_when_real_api_disabled(): void
    {
        Config::set('serverless.provisioner', 'cloudflare');
        Config::set('serverless.cloudflare.use_real_api', false);

        $this->assertInstanceOf(RoadmapStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_cloudflare_driver_uses_rest_provisioner_when_fully_configured(): void
    {
        Config::set('serverless.provisioner', 'cloudflare');
        Config::set('serverless.cloudflare.use_real_api', true);
        Config::set('serverless.cloudflare.account_id', 'test-account');
        Config::set('serverless.cloudflare.api_token', 'test-token');
        Config::set('serverless.cloudflare.script_path_prefix', sys_get_temp_dir());

        $this->assertInstanceOf(CloudflareWorkersProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_cloudflare_driver_uses_rest_provisioner_when_only_script_prefix_set(): void
    {
        Config::set('serverless.provisioner', 'cloudflare');
        Config::set('serverless.cloudflare.use_real_api', true);
        Config::set('serverless.cloudflare.account_id', '');
        Config::set('serverless.cloudflare.api_token', '');
        Config::set('serverless.cloudflare.script_path_prefix', sys_get_temp_dir());

        $this->assertInstanceOf(CloudflareWorkersProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_netlify_driver_uses_stub_when_real_api_disabled(): void
    {
        Config::set('serverless.provisioner', 'netlify');
        Config::set('serverless.netlify.use_real_api', false);

        $this->assertInstanceOf(RoadmapStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_netlify_driver_uses_rest_provisioner_when_configured(): void
    {
        Config::set('serverless.provisioner', 'netlify');
        Config::set('serverless.netlify.use_real_api', true);
        Config::set('serverless.netlify.api_token', 'nl-token');
        Config::set('serverless.netlify.site_id', 'nl-site');
        Config::set('serverless.netlify.zip_path_prefix', sys_get_temp_dir());

        $this->assertInstanceOf(NetlifyZipDeployProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_netlify_driver_falls_back_to_stub_when_zip_prefix_missing(): void
    {
        Config::set('serverless.provisioner', 'netlify');
        Config::set('serverless.netlify.use_real_api', true);
        Config::set('serverless.netlify.api_token', 'nl-token');
        Config::set('serverless.netlify.site_id', 'nl-site');
        Config::set('serverless.netlify.zip_path_prefix', null);

        $this->assertInstanceOf(RoadmapStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_vercel_driver_uses_stub_when_real_api_disabled(): void
    {
        Config::set('serverless.provisioner', 'vercel');
        Config::set('serverless.vercel.use_real_api', false);

        $this->assertInstanceOf(RoadmapStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_vercel_driver_uses_rest_provisioner_when_configured(): void
    {
        Config::set('serverless.provisioner', 'vercel');
        Config::set('serverless.vercel.use_real_api', true);
        Config::set('serverless.vercel.token', 'vc');
        Config::set('serverless.vercel.project_name', 'demo');
        Config::set('serverless.vercel.zip_path_prefix', sys_get_temp_dir());

        $this->assertInstanceOf(VercelZipDeployProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    public function test_vercel_driver_falls_back_to_stub_when_zip_prefix_missing(): void
    {
        Config::set('serverless.provisioner', 'vercel');
        Config::set('serverless.vercel.use_real_api', true);
        Config::set('serverless.vercel.token', 'vc');
        Config::set('serverless.vercel.project_name', 'demo');
        Config::set('serverless.vercel.zip_path_prefix', null);

        $this->assertInstanceOf(RoadmapStubProvisioner::class, $this->app->make(ServerlessFunctionProvisioner::class));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function roadmapProvisionerDriverProvider(): iterable
    {
        yield 'azure' => ['azure', 'azure:function:stub:'];
        yield 'gcp' => ['gcp', 'gcp:function:stub:'];
        yield 'cloudflare' => ['cloudflare', 'cloudflare:worker:stub:'];
        yield 'netlify' => ['netlify', 'netlify:function:stub:'];
        yield 'vercel' => ['vercel', 'vercel:function:stub:'];
    }

    #[DataProvider('roadmapProvisionerDriverProvider')]
    public function test_roadmap_driver_resolves_roadmap_stub(string $driver, string $arnPrefix): void
    {
        Config::set('serverless.provisioner', $driver);

        $provisioner = $this->app->make(ServerlessFunctionProvisioner::class);

        $this->assertInstanceOf(RoadmapStubProvisioner::class, $provisioner);
        $out = $provisioner->deployFunction('demo-fn', 'node22', '/tmp/x.zip');
        $this->assertSame($driver, $out['provider']);
        $this->assertSame($arnPrefix.'demo-fn', $out['function_arn']);
    }
}
