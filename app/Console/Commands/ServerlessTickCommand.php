<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives background work for serverless functions.
 *
 * DigitalOcean Functions has no long-running process, so dply's own
 * scheduler stands in as the cron: every minute this invokes each enabled
 * function with a signed header that puts the adapter into command mode,
 * running the Laravel scheduler and draining the queue. The shared secret
 * is the site's webhook_secret, which the deploy also injects into the
 * function as DPLY_COMMAND_SECRET.
 */
class ServerlessTickCommand extends Command
{
    protected $signature = 'serverless:tick';

    protected $description = 'Run the Laravel scheduler and queue worker on background-enabled serverless functions.';

    public function handle(): int
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

            $url = data_get($site->meta, 'serverless.action_url');
            if (! is_string($url) || $url === '') {
                continue;
            }

            if ($background) {
                // The scheduler / queue ticks also keep the function warm.
                $secret = trim((string) $site->webhook_secret);
                if ($secret === '') {
                    continue;
                }
                foreach (['schedule', 'queue'] as $task) {
                    $this->ping($site, $url, ['X-Dply-Run' => $task, 'X-Dply-Secret' => $secret], $task);
                }
            } else {
                // Keep-warm only — a plain request to hold a warm container.
                $this->ping($site, $url, [], 'keep-warm');
            }

            $ticked++;
        }

        $this->info('Ticked '.$ticked.' serverless function(s).');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function ping(Site $site, string $url, array $headers, string $task): void
    {
        try {
            Http::withHeaders($headers)->timeout(70)->get($url);
        } catch (Throwable $e) {
            Log::warning('serverless.tick.failed', [
                'site_id' => $site->id,
                'task' => $task,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
