<?php

namespace App\Providers;

use App\Contracts\DeployEngine;
use App\Contracts\ServerlessFunctionProvisioner;
use App\Features\ServerlessFeature;
use App\Serverless\Aws\AwsLambdaSdkProvisioner;
use App\Serverless\Aws\AwsSdkLambdaGateway;
use App\Serverless\Cloudflare\CloudflareWorkersProvisioner;
use App\Serverless\DigitalOcean\DigitalOceanOpenWhiskActionProvisioner;
use App\Serverless\Netlify\NetlifyZipDeployProvisioner;
use App\Serverless\Stub\AwsLambdaStubProvisioner;
use App\Serverless\Stub\DigitalOceanStubProvisioner;
use App\Serverless\Stub\LocalStubProvisioner;
use App\Serverless\Stub\RoadmapStubProvisioner;
use App\Serverless\Vercel\VercelZipDeployProvisioner;
use App\Services\Deploy\ServerlessDeployEngine;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature as PennantFeature;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ServerlessFunctionProvisioner::class, function (): ServerlessFunctionProvisioner {
            return match (config('serverless.provisioner', 'local')) {
                'aws' => $this->awsProvisioner(),
                'azure' => new RoadmapStubProvisioner('azure', 'azure:function:stub:', 'azure-stub-revision-1'),
                'cloudflare' => $this->cloudflareProvisioner(),
                'digitalocean' => $this->digitaloceanProvisioner(),
                'gcp' => new RoadmapStubProvisioner('gcp', 'gcp:function:stub:', 'gcp-stub-revision-1'),
                'netlify' => $this->netlifyProvisioner(),
                'vercel' => $this->vercelProvisioner(),
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
        PennantFeature::define(ServerlessFeature::INTERNAL_SPIKE, function (): bool {
            $raw = env('SERVERLESS_INTERNAL_SPIKE');

            if ($raw !== null) {
                return filter_var($raw, FILTER_VALIDATE_BOOL);
            }

            return app()->environment('testing', 'local');
        });

        PennantFeature::define(ServerlessFeature::PUBLIC_DASHBOARD, function (): bool {
            $raw = env('SERVERLESS_PUBLIC_DASHBOARD');

            if ($raw !== null) {
                return filter_var($raw, FILTER_VALIDATE_BOOL);
            }

            return true;
        });
    }

    private function awsProvisioner(): ServerlessFunctionProvisioner
    {
        if (config('serverless.aws.use_real_sdk')) {
            $region = (string) config('serverless.aws.region');
            $gateway = AwsSdkLambdaGateway::fromConfigRegion($region);

            return new AwsLambdaSdkProvisioner(
                $gateway,
                $region,
                (bool) config('serverless.aws.upload_zip_when_file_exists'),
                config('serverless.aws.zip_path_prefix'),
                (int) config('serverless.aws.zip_max_bytes'),
                (array) config('serverless.aws.s3_allow_buckets'),
            );
        }

        return new AwsLambdaStubProvisioner;
    }

    private function cloudflareProvisioner(): ServerlessFunctionProvisioner
    {
        if (! config('serverless.cloudflare.use_real_api')) {
            return new RoadmapStubProvisioner('cloudflare', 'cloudflare:worker:stub:', 'cloudflare-stub-revision-1');
        }

        $accountId = trim((string) config('serverless.cloudflare.account_id'));
        $token = trim((string) config('serverless.cloudflare.api_token'));
        $prefix = config('serverless.cloudflare.script_path_prefix');
        if ($prefix === null || trim((string) $prefix) === '') {
            return new RoadmapStubProvisioner('cloudflare', 'cloudflare:worker:stub:', 'cloudflare-stub-revision-1');
        }

        return new CloudflareWorkersProvisioner(
            $accountId,
            $token,
            (string) config('serverless.cloudflare.compatibility_date'),
            rtrim((string) $prefix, DIRECTORY_SEPARATOR),
            (int) config('serverless.cloudflare.script_max_bytes'),
        );
    }

    private function digitaloceanProvisioner(): ServerlessFunctionProvisioner
    {
        if (! config('serverless.digitalocean.use_real_api')) {
            return new DigitalOceanStubProvisioner;
        }

        $prefix = config('serverless.digitalocean.zip_path_prefix');
        if ($prefix === null || trim((string) $prefix) === '') {
            return new DigitalOceanStubProvisioner;
        }

        $apiHost = trim((string) config('serverless.digitalocean.api_host'));
        $namespace = trim((string) config('serverless.digitalocean.namespace'));
        $accessKey = trim((string) config('serverless.digitalocean.access_key'));
        if ($apiHost === '' || $namespace === '' || $accessKey === '') {
            return new DigitalOceanStubProvisioner;
        }

        return new DigitalOceanOpenWhiskActionProvisioner(
            $apiHost,
            $namespace,
            $accessKey,
            rtrim((string) $prefix, DIRECTORY_SEPARATOR),
            (int) config('serverless.digitalocean.zip_max_bytes'),
            (string) config('serverless.digitalocean.default_action_kind'),
            (string) config('serverless.digitalocean.default_action_main'),
            (string) config('serverless.digitalocean.default_package'),
        );
    }

    private function netlifyProvisioner(): ServerlessFunctionProvisioner
    {
        if (! config('serverless.netlify.use_real_api')) {
            return new RoadmapStubProvisioner('netlify', 'netlify:function:stub:', 'netlify-stub-revision-1');
        }

        $prefix = config('serverless.netlify.zip_path_prefix');
        if ($prefix === null || trim((string) $prefix) === '') {
            return new RoadmapStubProvisioner('netlify', 'netlify:function:stub:', 'netlify-stub-revision-1');
        }

        return new NetlifyZipDeployProvisioner(
            trim((string) config('serverless.netlify.api_token')),
            trim((string) config('serverless.netlify.site_id')),
            rtrim((string) $prefix, DIRECTORY_SEPARATOR),
            (int) config('serverless.netlify.zip_max_bytes'),
        );
    }

    private function vercelProvisioner(): ServerlessFunctionProvisioner
    {
        if (! config('serverless.vercel.use_real_api')) {
            return new RoadmapStubProvisioner('vercel', 'vercel:function:stub:', 'vercel-stub-revision-1');
        }

        $prefix = config('serverless.vercel.zip_path_prefix');
        if ($prefix === null || trim((string) $prefix) === '') {
            return new RoadmapStubProvisioner('vercel', 'vercel:function:stub:', 'vercel-stub-revision-1');
        }

        return new VercelZipDeployProvisioner(
            trim((string) config('serverless.vercel.token')),
            trim((string) config('serverless.vercel.team_id')),
            trim((string) config('serverless.vercel.project_id')),
            trim((string) config('serverless.vercel.project_name')),
            rtrim((string) $prefix, DIRECTORY_SEPARATOR),
            (int) config('serverless.vercel.zip_max_bytes'),
            (int) config('serverless.vercel.max_zip_entries'),
            (int) config('serverless.vercel.max_uncompressed_bytes'),
        );
    }
}
