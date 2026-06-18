<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Console;

use App\Models\Site;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives background work for serverless functions.
 *
 * DigitalOcean Functions has no long-running process, so dply's own
 * scheduler stands in as the cron: every minute this invokes each enabled
 * function. A background-enabled function gets a `schedule` and a `queue`
 * tick (which also keep it warm); a keep-warm-only function gets a plain
 * `keep-warm` tick.
 *
 * Each tick goes through {@see InvokeFunctionTick}, which records it as a
 * `source=tick` FunctionInvocation — the same path the in-UI "Tick now"
 * buttons use.
 */
class ServerlessTickCommand extends Command
{
    protected $signature = 'serverless:tick';

    protected $description = 'Run the Laravel scheduler and queue worker on background-enabled serverless functions.';

    public function handle(InvokeFunctionTick $tick): int
    {
        $sites = Site::query()
            ->where('status', Site::STATUS_FUNCTIONS_ACTIVE)
            ->get();

        $ticked = 0;

        foreach ($sites as $site) {
            $background = data_get($site->meta, 'serverless.background_enabled') === true;
            $keepWarm = data_get($site->meta, 'serverless.keep_warm') === true;

            if (! $background && ! $keepWarm) {
                continue;
            }

            // A background function needs scheduler + queue work; those ticks
            // also keep it warm, so keep-warm-only is the fallback.
            $tasks = $background ? ['schedule', 'queue'] : ['keep-warm'];

            foreach ($tasks as $task) {
                try {
                    $tick->tickSite($site, $task);
                } catch (Throwable $e) {
                    Log::warning('serverless.tick.failed', [
                        'site_id' => $site->id,
                        'task' => $task,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $ticked++;
        }

        $this->info('Ticked '.$ticked.' serverless function(s).');

        return self::SUCCESS;
    }
}
