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
                            {--kv-title=dply-edge-host-map : KV namespace title for host map}
                            {--cache-kv-title=dply-edge-cache : KV namespace title for hybrid origin cache}
                            {--dispatch-name=dply-edge-ssr : Workers for Platforms dispatch namespace for SSR scripts}
                            {--skip-dispatch : Skip creating the dispatch namespace (SSR sites won\'t work)}
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
        $cacheKvTitle = (string) $this->option('cache-kv-title');
        $dispatchName = (string) $this->option('dispatch-name');
        $skipDispatch = (bool) $this->option('skip-dispatch');

        if ($this->option('dry-run')) {
            $this->info('[dry-run] Would verify Cloudflare token');
            $this->line('[dry-run] R2 bucket: '.$bucket);
            $this->line('[dry-run] KV namespace title (host map): '.$kvTitle);
            $this->line('[dry-run] KV namespace title (origin cache): '.$cacheKvTitle);
            if ($skipDispatch) {
                $this->line('[dry-run] Skipping dispatch namespace (Phase 4b SSR will be unavailable).');
            } else {
                $this->line('[dry-run] Dispatch namespace (Phase 4b SSR): '.$dispatchName);
            }
            $this->printEnvTemplate(
                $bucket,
                (string) config('edge.cloudflare.kv_namespace_id'),
                $accountId,
                (string) config('edge.cloudflare.cache_kv_namespace_id'),
                $skipDispatch ? '' : $dispatchName,
                (string) config('edge.cloudflare.dispatch_namespace_id'),
            );

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

            $cacheKvId = (string) config('edge.cloudflare.cache_kv_namespace_id');
            if ($cacheKvId === '') {
                $existingCache = $client->kvNamespaceIdByTitle($cacheKvTitle);
                if ($existingCache !== null) {
                    $cacheKvId = $existingCache;
                    $this->line('Cache KV namespace already exists: '.$cacheKvTitle.' ('.$cacheKvId.')');
                } else {
                    $createdCache = $client->createKvNamespace($cacheKvTitle);
                    $cacheKvId = is_string($createdCache['id'] ?? null) ? $createdCache['id'] : '';
                    $this->info('Created cache KV namespace: '.$cacheKvTitle.' ('.$cacheKvId.')');
                }
            } else {
                $this->line('Using configured cache KV namespace id: '.$cacheKvId);
            }

            $dispatchId = (string) config('edge.cloudflare.dispatch_namespace_id');
            $resolvedDispatchName = '';
            if (! $skipDispatch) {
                try {
                    $existingDispatch = $client->dispatchNamespaceIdByName($dispatchName);
                    if ($existingDispatch !== null) {
                        $dispatchId = $existingDispatch;
                        $resolvedDispatchName = $dispatchName;
                        $this->line('Dispatch namespace already exists: '.$dispatchName.' ('.$dispatchId.')');
                    } else {
                        $createdDispatch = $client->createDispatchNamespace($dispatchName);
                        $newId = $createdDispatch['namespace_id'] ?? $createdDispatch['id'] ?? '';
                        $dispatchId = is_string($newId) ? $newId : '';
                        $resolvedDispatchName = $dispatchName;
                        $this->info('Created dispatch namespace: '.$dispatchName.' ('.$dispatchId.')');
                    }
                } catch (\Throwable $e) {
                    $this->warn('Could not create dispatch namespace ('.$e->getMessage().').');
                    $this->warn('SSR Edge sites (runtime_mode=ssr) will be blocked until this is set up.');
                    $this->warn('Workers for Platforms requires the Workers Paid plan + dispatch namespace permission on the API token.');
                }
            } else {
                $this->line('Skipped dispatch namespace (--skip-dispatch). SSR sites disabled.');
            }

            $this->newLine();
            $this->printEnvTemplate($bucket, $kvId, $accountId, $cacheKvId, $resolvedDispatchName, $dispatchId);
            $this->newLine();
            $this->warn('Create an R2 API token in Cloudflare (R2 → Manage R2 API tokens) with read/write on this bucket.');
            $this->line('Then run: php artisan dply:edge:doctor --probe');
            $this->line('Ensure delivery features: php artisan dply:edge:ensure-delivery-features');
            $this->line('Deploy worker: php artisan edge:worker:deploy');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function printEnvTemplate(string $bucket, string $kvId, string $accountId, string $cacheKvId = '', string $dispatchName = '', string $dispatchId = ''): void
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
        if ($cacheKvId !== '') {
            $this->line('DPLY_EDGE_CF_CACHE_KV_NAMESPACE_ID='.$cacheKvId);
        }
        if ($dispatchName !== '') {
            $this->line('DPLY_EDGE_CF_DISPATCH_NAMESPACE='.$dispatchName);
        }
        if ($dispatchId !== '') {
            $this->line('DPLY_EDGE_CF_DISPATCH_NAMESPACE_ID='.$dispatchId);
        }
        $this->line('DPLY_EDGE_CF_WORKER_SCRIPT=dply-edge');
        if ($zone !== '' && $routes !== []) {
            $this->line('DPLY_EDGE_CF_ZONE_NAME='.$zone);
            $this->line('DPLY_EDGE_CF_WORKER_ROUTES='.implode(',', $routes));
        }
    }
}
