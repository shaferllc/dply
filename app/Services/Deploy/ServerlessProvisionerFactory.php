<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\AwsLambdaGateway;
use App\Contracts\ServerlessFunctionProvisioner;
use App\Services\Deploy\ServerlessProviders\Aws\AwsLambdaSdkProvisioner;
use App\Services\Deploy\ServerlessProviders\Aws\AwsSdkLambdaGateway;
use App\Services\Deploy\ServerlessProviders\Cloudflare\CloudflareWorkersProvisioner;
use App\Services\Deploy\ServerlessProviders\DigitalOcean\DigitalOceanOpenWhiskActionProvisioner;
use App\Services\Deploy\ServerlessProviders\Netlify\NetlifyZipDeployProvisioner;
use App\Services\Deploy\ServerlessProviders\Stub\AwsLambdaStubProvisioner;
use App\Services\Deploy\ServerlessProviders\Stub\DigitalOceanStubProvisioner;
use App\Services\Deploy\ServerlessProviders\Stub\LocalStubProvisioner;
use App\Services\Deploy\ServerlessProviders\Stub\RoadmapStubProvisioner;
use App\Services\Deploy\ServerlessProviders\Vercel\VercelZipDeployProvisioner;

final class ServerlessProvisionerFactory
{
    public function __construct(
        private readonly AwsLambdaGateway $awsLambdaGateway,
    ) {}

    public function make(string $driver): ServerlessFunctionProvisioner
    {
        return match ($driver) {
            'aws' => $this->awsProvisioner(),
            'azure' => new RoadmapStubProvisioner('azure', 'azure:function:stub:', 'azure-stub-revision-1'),
            'cloudflare' => $this->cloudflareProvisioner(),
            'digitalocean' => $this->digitalOceanProvisioner(),
            'gcp' => new RoadmapStubProvisioner('gcp', 'gcp:function:stub:', 'gcp-stub-revision-1'),
            'netlify' => $this->netlifyProvisioner(),
            'vercel' => $this->vercelProvisioner(),
            default => new LocalStubProvisioner,
        };
    }

    private function awsProvisioner(): ServerlessFunctionProvisioner
    {
        if (config('serverless.aws.use_real_sdk')) {
            return new AwsLambdaSdkProvisioner(
                $this->awsLambdaGateway,
                (string) config('serverless.aws.region'),
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

        $prefix = config('serverless.cloudflare.script_path_prefix');
        if ($prefix === null || trim((string) $prefix) === '') {
            return new RoadmapStubProvisioner('cloudflare', 'cloudflare:worker:stub:', 'cloudflare-stub-revision-1');
        }

        return new CloudflareWorkersProvisioner(
            (string) config('serverless.cloudflare.account_id'),
            (string) config('serverless.cloudflare.api_token'),
            (string) config('serverless.cloudflare.compatibility_date'),
            rtrim((string) $prefix, DIRECTORY_SEPARATOR),
            (int) config('serverless.cloudflare.script_max_bytes'),
        );
    }

    private function digitalOceanProvisioner(): ServerlessFunctionProvisioner
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
            (string) config('serverless.netlify.api_token'),
            (string) config('serverless.netlify.site_id'),
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
            (string) config('serverless.vercel.token'),
            (string) config('serverless.vercel.team_id'),
            (string) config('serverless.vercel.project_id'),
            (string) config('serverless.vercel.project_name'),
            rtrim((string) $prefix, DIRECTORY_SEPARATOR),
            (int) config('serverless.vercel.zip_max_bytes'),
            (int) config('serverless.vercel.max_zip_entries'),
            (int) config('serverless.vercel.max_uncompressed_bytes'),
        );
    }

    public static function defaultAwsGateway(): AwsLambdaGateway
    {
        return AwsSdkLambdaGateway::fromConfigRegion((string) config('serverless.aws.region', 'us-east-1'));
    }
}
