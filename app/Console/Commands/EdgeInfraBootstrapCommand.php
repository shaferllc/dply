<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Edge\EdgeCloudflareClient;
use App\Support\Edge\EdgePlatformCredentials;
use Illuminate\Console\Command;

/**
 * Bootstrap Cloudflare R2 bucket + Workers KV namespace for dply Edge.
 *
 * Creates resources via the Cloudflare API when they do not exist yet.
 * R2 S3 access keys must still be created in the Cloudflare dashboard
 * (Account → R2 → Manage R2 API tokens).
 */
class EdgeInfraBootstrapCommand extends Command
{
    protected $signature = 'dply:edge:infra:bootstrap
                            {--bucket= : R2 bucket name (default: dply-edge-artifacts)}
                            {--kv-title=dply-edge-host-map : KV namespace title}
                            {--dry-run : Print planned actions without calling Cloudflare}';

    protected $description = 'Create Edge R2 bucket and KV namespace via Cloudflare API';

    public function handle(): int
    {
        $accountId = trim((string) config('edge.cloudflare.account_id'));
        $token = trim((string) config('edge.cloudflare.api_token'));

        if ($accountId === '' || $token === '') {
            $this->error('Set DPLY_EDGE_CF_ACCOUNT_ID and DPLY_EDGE_CF_API_TOKEN before bootstrapping.');

            return self::FAILURE;
        }

        $bucket = (string) ($this->option('bucket') ?: config('edge.r2.bucket') ?: 'dply-edge-artifacts');
        $kvTitle = (string) $this->option('kv-title');

        if ($this->option('dry-run')) {
            $this->info('[dry-run] Would verify Cloudflare token');
            $this->line('[dry-run] R2 bucket: '.$bucket);
            $this->line('[dry-run] KV namespace title: '.$kvTitle);
            $this->printEnvTemplate($bucket, (string) config('edge.cloudflare.kv_namespace_id'), $accountId);

            return self::SUCCESS;
        }

        try {
            $client = EdgeCloudflareClient::fromConfig();
            $verify = $client->verifyToken();
            $this->info('Cloudflare token verified ('.(string) ($verify['status'] ?? 'ok').').');

            if ($client->r2BucketExists($bucket)) {
                $this->line('R2 bucket already exists: '.$bucket);
            } else {
                $client->createR2Bucket($bucket);
                $this->info('Created R2 bucket: '.$bucket);
            }

            $kvId = (string) config('edge.cloudflare.kv_namespace_id');
            if ($kvId === '') {
                $existing = $client->kvNamespaceIdByTitle($kvTitle);
                if ($existing !== null) {
                    $kvId = $existing;
                    $this->line('KV namespace already exists: '.$kvTitle.' ('.$kvId.')');
                } else {
                    $created = $client->createKvNamespace($kvTitle);
                    $kvId = is_string($created['id'] ?? null) ? $created['id'] : '';
                    $this->info('Created KV namespace: '.$kvTitle.' ('.$kvId.')');
                }
            } else {
                $this->line('Using configured KV namespace id: '.$kvId);
            }

            $this->newLine();
            $this->printEnvTemplate($bucket, $kvId, $accountId);
            $this->newLine();
            $this->warn('Create an R2 API token in Cloudflare (R2 → Manage R2 API tokens) with read/write on this bucket.');
            $this->line('Then run: php artisan dply:edge:doctor --probe');
            $this->line('Deploy worker: php artisan edge:worker:deploy');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function printEnvTemplate(string $bucket, string $kvId, string $accountId): void
    {
        $endpoint = EdgePlatformCredentials::r2Endpoint() ?: 'https://'.$accountId.'.r2.cloudflarestorage.com';
        $routes = EdgePlatformCredentials::workerRoutes();
        $zone = trim((string) config('edge.cloudflare.worker_zone_name'));

        $this->info('Add to production .env:');
        $this->line('');
        $this->line('DPLY_FAKE_EDGE=false');
        $this->line('FEATURE_SURFACE_EDGE=true');
        $this->line('DPLY_EDGE_R2_BUCKET='.$bucket);
        $this->line('DPLY_EDGE_R2_REGION=auto');
        $this->line('DPLY_EDGE_R2_ENDPOINT='.$endpoint);
        $this->line('DPLY_EDGE_R2_ACCESS_KEY=<from Cloudflare R2 API token>');
        $this->line('DPLY_EDGE_R2_SECRET=<from Cloudflare R2 API token>');
        $this->line('DPLY_EDGE_CF_ACCOUNT_ID='.$accountId);
        $this->line('DPLY_EDGE_CF_API_TOKEN=<same or dedicated Workers/KV token>');
        if ($kvId !== '') {
            $this->line('DPLY_EDGE_CF_KV_NAMESPACE_ID='.$kvId);
        }
        $this->line('DPLY_EDGE_CF_WORKER_SCRIPT=dply-edge');
        if ($zone !== '' && $routes !== []) {
            $this->line('DPLY_EDGE_CF_ZONE_NAME='.$zone);
            $this->line('DPLY_EDGE_CF_WORKER_ROUTES='.implode(',', $routes));
        }
    }
}
