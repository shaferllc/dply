<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Deploy the dply Edge Cloudflare Worker package.
 *
 * Stub — full wiring lands with Phase 1 infra automation.
 */
class EdgeWorkerDeployCommand extends Command
{
    protected $signature = 'edge:worker:deploy
                            {--dry-run : Print the deploy steps without executing them}';

    protected $description = 'Deploy the dply Edge Cloudflare Worker (stub)';

    public function handle(): int
    {
        $workerPath = base_path('packages/edge-worker');
        $script = (string) config('edge.cloudflare.worker_script_name', 'dply-edge');

        if ($this->option('dry-run')) {
            $this->line('Would deploy Cloudflare Worker: '.$script);
            $this->line('Package path: '.$workerPath);
            $this->line('Run manually: cd packages/edge-worker && npm run deploy');

            return self::SUCCESS;
        }

        $this->info('Edge worker deploy is not automated yet.');
        $this->line('Deploy manually from packages/edge-worker:');
        $this->line('  cd packages/edge-worker');
        $this->line('  npm install');
        $this->line('  npm run deploy');
        $this->newLine();
        $this->line('Worker script name (config): '.$script);
        $this->line('KV namespace: '.(string) config('edge.cloudflare.kv_namespace_id'));
        $this->line('R2 bucket: '.(string) config('edge.r2.bucket'));

        return self::SUCCESS;
    }
}
