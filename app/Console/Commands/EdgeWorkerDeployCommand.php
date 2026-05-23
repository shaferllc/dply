<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProviderCredential;
use App\Services\Edge\EdgeDeliveryContextResolver;
use App\Support\Edge\EdgeDeliveryContext;
use App\Support\Edge\EdgePlatformCredentials;
use App\Support\Edge\EdgeWranglerConfigGenerator;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class EdgeWorkerDeployCommand extends Command
{
    protected $signature = 'edge:worker:deploy
                            {--credential= : Deploy to an org Cloudflare credential instead of platform}
                            {--dry-run : Generate Wrangler config and print deploy steps only}';

    protected $description = 'Deploy the dply Edge Cloudflare Worker from packages/edge-worker';

    public function handle(
        EdgeWranglerConfigGenerator $generator,
        EdgeDeliveryContextResolver $contextResolver,
    ): int {
        if (FakeEdgeProvision::enabled()) {
            $this->error('DPLY_FAKE_EDGE is enabled. Disable fake edge before deploying the production worker.');

            return self::FAILURE;
        }

        $context = $this->resolveContext($contextResolver);
        if ($context === null) {
            return self::FAILURE;
        }

        $workerPath = base_path('packages/edge-worker');
        if (! is_dir($workerPath)) {
            $this->error('Worker package not found at packages/edge-worker');

            return self::FAILURE;
        }

        try {
            $configPath = $generator->write($context);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Generated Wrangler config: '.$configPath);

        if ($this->option('dry-run')) {
            $this->line('Would run: cd packages/edge-worker && npm ci && npx wrangler deploy --config wrangler.generated.toml');
            $this->line('Worker script: '.$context->workerScriptName);
            $this->line('R2 bucket: '.$context->r2Bucket);
            $this->line('KV namespace: '.$context->kvNamespaceId);

            return self::SUCCESS;
        }

        $install = Process::path($workerPath)->timeout(600)->run(['npm', 'ci']);
        if (! $install->successful()) {
            $this->error('npm ci failed:');
            $this->line($install->errorOutput());

            return self::FAILURE;
        }

        $deploy = Process::path($workerPath)
            ->timeout(600)
            ->env([
                'CLOUDFLARE_API_TOKEN' => $context->apiToken,
                'CLOUDFLARE_ACCOUNT_ID' => $context->accountId,
            ])
            ->run(['npx', 'wrangler', 'deploy', '--config', 'wrangler.generated.toml']);

        if (! $deploy->successful()) {
            $this->error('wrangler deploy failed:');
            $this->line($deploy->errorOutput());
            $this->line($deploy->output());

            return self::FAILURE;
        }

        $this->info('Edge worker deployed.');
        $this->line($deploy->output());
        $this->newLine();
        $this->line('Verify: php artisan dply:edge:doctor --probe');

        return self::SUCCESS;
    }

    private function resolveContext(EdgeDeliveryContextResolver $contextResolver): ?EdgeDeliveryContext
    {
        $credentialId = trim((string) $this->option('credential'));
        if ($credentialId !== '') {
            $credential = ProviderCredential::query()->find($credentialId);
            if ($credential === null) {
                $this->error('Provider credential not found.');

                return null;
            }

            try {
                return $contextResolver->forProviderCredential($credential);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return null;
            }
        }

        $missing = EdgePlatformCredentials::missing();
        if ($missing !== []) {
            $this->error('Missing Edge platform credentials:');
            foreach ($missing as $item) {
                $this->line('  - '.$item);
            }
            $this->line('Run: php artisan dply:edge:infra:bootstrap');

            return null;
        }

        return EdgeDeliveryContext::platform();
    }
}
