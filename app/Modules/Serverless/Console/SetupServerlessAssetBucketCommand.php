<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Modules\Serverless\Services\ServerlessAssetPublisher;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-time setup for the shared asset bucket, plus the Cloudflare values that
 * have to be entered by hand.
 *
 *   php artisan dply:serverless:setup-asset-bucket
 *   php artisan dply:serverless:setup-asset-bucket --dry-run
 *
 * Reads the `serverless_assets` disk's own credentials rather than taking its
 * own, so a successful run also proves the disk is configured correctly —
 * which is exactly what the backfill and the publisher will use next.
 *
 * Deliberately does NOT touch the bucket ACL. Spaces buckets are private by
 * default and the publisher marks each object public individually, which is
 * the shape we want: objects readable, `ListBucket` denied, so the prefix
 * cannot be enumerated even by someone who guesses the origin.
 */
class SetupServerlessAssetBucketCommand extends Command
{
    protected $signature = 'dply:serverless:setup-asset-bucket
                            {--dry-run : Report what would be done without creating or changing anything}';

    protected $description = 'Create the shared serverless asset bucket, apply CORS, and print the Cloudflare setup values.';

    /** Provider slug the shared asset bucket is created on. */
    private const PROVIDER = 'digitalocean_spaces';

    public function handle(ObjectStorageBucketProvisioner $provisioner): int
    {
        $disk = (array) config('filesystems.disks.'.ServerlessAssetPublisher::DISK, []);

        if (($disk['driver'] ?? null) !== 's3') {
            $this->error('The serverless_assets disk is not configured for object storage.');
            $this->line('Set SERVERLESS_ASSETS_DISK_DRIVER=s3 plus SERVERLESS_ASSETS_S3_BUCKET / _KEY / _SECRET / _REGION.');

            return self::FAILURE;
        }

        $bucket = trim((string) ($disk['bucket'] ?? ''));
        $region = trim((string) ($disk['region'] ?? ''));
        $key = trim((string) ($disk['key'] ?? ''));
        $secret = trim((string) ($disk['secret'] ?? ''));

        foreach (['bucket' => $bucket, 'region' => $region, 'key' => $key, 'secret' => $secret] as $label => $value) {
            if ($value === '') {
                $this->error("The serverless_assets disk has no {$label} configured.");

                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->line("[dry-run] would ensure bucket {$bucket} in {$region} and apply CORS");
            $this->printCloudflareSetup($bucket, $region, $disk);

            return self::SUCCESS;
        }

        try {
            // Idempotent: BucketAlreadyOwnedByYou is treated as success, so
            // this doubles as "adopt the bucket I already made".
            $created = $provisioner->create(
                self::PROVIDER,
                $region,
                $key,
                $secret,
                $bucket,
            );
        } catch (Throwable $e) {
            $this->error('Could not create the bucket: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Bucket ready: {$bucket} ({$region})");

        if (! $this->applyCors($bucket, $region, $key, $secret, (string) ($disk['endpoint'] ?? $created['endpoint']))) {
            return self::FAILURE;
        }

        $this->printCloudflareSetup($bucket, $region, $disk);

        return self::SUCCESS;
    }

    /**
     * Assets are fetched cross-origin once they move to a CDN hostname, and
     * Vite emits `<script type="module" crossorigin>` while CSS-referenced
     * fonts are CORS-gated — so this is required, not optional.
     *
     * `*` is correct here: these are public files served to any origin, and a
     * specific origin would force `Vary: Origin` and fragment the cache. The
     * Cloudflare response-header rule is the authority for cache hits; this
     * covers origin responses and any direct pull.
     */
    private function applyCors(string $bucket, string $region, string $key, string $secret, string $endpoint): bool
    {
        try {
            $client = new S3Client([
                'version' => 'latest',
                'region' => $region,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'credentials' => ['key' => $key, 'secret' => $secret],
                'http' => ['connect_timeout' => 5, 'timeout' => 20],
            ]);

            $client->putBucketCors([
                'Bucket' => $bucket,
                'CORSConfiguration' => [
                    'CORSRules' => [[
                        'AllowedHeaders' => ['*'],
                        'AllowedMethods' => ['GET', 'HEAD'],
                        'AllowedOrigins' => ['*'],
                        'ExposeHeaders' => ['ETag', 'Content-Length'],
                        'MaxAgeSeconds' => 3600,
                    ]],
                ],
            ]);
        } catch (Throwable $e) {
            $this->error('Could not apply the CORS policy: '.$e->getMessage());

            return false;
        }

        $this->info('CORS applied (GET/HEAD from any origin).');

        return true;
    }

    /**
     * @param  array<string, mixed>  $disk
     */
    private function printCloudflareSetup(string $bucket, string $region, array $disk): void
    {
        $endpointHost = (string) parse_url(
            (string) ($disk['endpoint'] ?? "https://{$region}.digitaloceanspaces.com"),
            PHP_URL_HOST,
        );
        $origin = $bucket.'.'.$endpointHost;

        $this->newLine();
        $this->line('<comment>Cloudflare setup — one rule per serverless apex zone.</comment>');
        $this->line('These cannot be provisioned from here; enter them in the dashboard.');
        $this->newLine();
        $this->line("  Origin (virtual-host style, so the path maps straight onto the key):");
        $this->line("    {$origin}");
        $this->newLine();

        foreach (ServerlessTestingDomains::all() as $apex) {
            $this->line("  Zone {$apex}");
            $this->line('    Match:');
            $this->line('      http.host matches "'.ServerlessAssetHost::hostRegex($apex).'"');
            $this->line('    Rewrite URI path to:');
            $this->line('      concat("/'.ServerlessAssetHost::STORAGE_PREFIX.'/",');
            $this->line('             regex_replace(http.host, "'.ServerlessAssetHost::hostRegex($apex).'", "${1}"),');
            $this->line('             http.request.uri.path)');
            $this->newLine();
        }

        $this->line('  Also set, on those hostnames:');
        $this->line('    - Response header  Access-Control-Allow-Origin: *');
        $this->line('    - A cache rule with a long edge TTL (assets are content-hashed + immutable)');
        $this->newLine();
        $this->line('  The bucket ACL is left private on purpose: objects are public individually,');
        $this->line('  so ListBucket stays denied and the prefix cannot be enumerated.');
        $this->newLine();
        $this->line('Then: <info>php artisan dply:serverless:backfill-assets</info>, and set');
        $this->line('<info>DPLY_SERVERLESS_ASSET_CDN_ENABLED=true</info> once it reports clean.');
    }
}
