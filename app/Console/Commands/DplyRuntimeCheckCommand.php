<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DplyRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Validate control-plane runtime placement (web vs worker split).
 *
 * Intended for cron on each worker VM, e.g. every five minutes:
 *
 *   * * * * * cd /var/www/dply/current && php artisan dply:runtime:check --quiet
 */
class DplyRuntimeCheckCommand extends Command
{
    protected $signature = 'dply:runtime:check
                            {--skip-horizon : Do not verify Horizon is active on worker nodes}';

    protected $description = 'Verify DPLY_RUNTIME placement, env, and (on workers) Horizon status.';

    public function handle(): int
    {
        $issues = DplyRuntime::configurationIssues();

        foreach ($issues as $issue) {
            $this->error($issue);
        }

        if (DplyRuntime::expectsHorizon() && DplyRuntime::isSplitDeployment() && ! $this->option('skip-horizon')) {
            $horizonExit = Artisan::call('horizon:status');
            if ($horizonExit !== self::SUCCESS) {
                $issues[] = 'Horizon is not running on this worker node.';
                $this->error(trim(Artisan::output()) !== '' ? trim(Artisan::output()) : 'Horizon is not running on this worker node.');
            }
        }

        if ($issues !== []) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Runtime OK (%s%s).',
            DplyRuntime::mode(),
            DplyRuntime::mode() === DplyRuntime::MODE_WORKER
                ? ', role='.DplyRuntime::workerRole()
                : '',
        ));

        return self::SUCCESS;
    }
}
