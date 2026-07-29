<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\EdgeDeliveryFeaturesEnsurer;
use App\Modules\Edge\Support\EdgePlatformCredentials;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Console\Command;

class EdgeEnsureDeliveryFeaturesCommand extends Command
{
    protected $signature = 'dply:edge:ensure-delivery-features
                            {--skip-worker : Generate config only; do not run edge:worker:deploy}';

    protected $description = 'Ensure Edge cache KV, Image Resizing on delivery zones, and image optimization on live sites';

    public function handle(EdgeDeliveryFeaturesEnsurer $ensurer): int
    {
        if (FakeEdgeProvision::enabled()) {
            $this->error('DPLY_FAKE_EDGE is enabled. Disable fake edge before running production delivery setup.');

            return self::FAILURE;
        }

        if (EdgePlatformCredentials::missing() !== []) {
            $this->error('Missing Edge platform credentials. Run: php artisan dply:edge:infra:bootstrap');

            return self::FAILURE;
        }

        try {
            $result = $ensurer->ensurePlatform(deployWorker: ! $this->option('skip-worker'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $cacheId = $result['cache_kv_namespace_id'];
        if ($result['cache_kv_created']) {
            $this->info('Created KV namespace '.EdgeDeliveryFeaturesEnsurer::CACHE_KV_TITLE.' ('.$cacheId.')');
        } else {
            $this->line('Cache KV namespace: '.$cacheId);
        }

        $configured = trim((string) config('edge.cloudflare.cache_kv_namespace_id', ''));
        if ($configured === '' || $configured !== $cacheId) {
            $this->newLine();
            $this->warn('Add or update in .env:');
            $this->line('DPLY_EDGE_CF_CACHE_KV_NAMESPACE_ID='.$cacheId);
        }

        foreach ($result['image_zones'] as $zoneResult) {
            $line = $zoneResult['zone'].': '.$zoneResult['detail'];
            if ($zoneResult['ok']) {
                $this->info($line);
            } else {
                $this->warn($line);
            }
        }

        if ($result['sites_enabled'] !== []) {
            $this->info('Image optimization enabled on: '.implode(', ', $result['sites_enabled']));
        } else {
            $this->line('No live Edge sites to enable image optimization on.');
        }

        if ($result['worker_deployed']) {
            $this->info('Edge worker redeployed with EDGE_CACHE binding.');
        } elseif (! $this->option('skip-worker')) {
            $this->warn('Worker was not redeployed.');
        }

        if ($result['access_gates_republished'] > 0) {
            $this->info('Preview access gates republished for '.$result['access_gates_republished'].' site(s).');
        }

        return self::SUCCESS;
    }
}
