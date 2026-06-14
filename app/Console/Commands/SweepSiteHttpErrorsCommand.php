<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SiteType;
use App\Jobs\SweepSiteHttpErrorsJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Tier-2 5xx auto-capture: dispatch a {@see SweepSiteHttpErrorsJob} for every
 * eligible site so application 500s surface on the Errors tab on their own. Only
 * PHP-FPM sites on SSH-managed, ready servers have the per-pool access log this
 * reads — container/serverless/worker/Apache/OLS sites are skipped.
 */
class SweepSiteHttpErrorsCommand extends Command
{
    protected $signature = 'dply:sweep-site-http-errors';

    protected $description = 'Capture recent HTTP 5xx responses from managed PHP-FPM sites as error events.';

    public function handle(): int
    {
        if (! config('server_error_codes.sweep_enabled', true)) {
            $this->components->info('5xx error sweeping is disabled.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        Site::query()
            ->where('type', SiteType::Php->value)
            ->whereNull('octane_port') // octane sites don't run PHP-FPM
            ->whereNull('suspended_at')
            ->whereNull('scheduled_deletion_at')
            ->whereHas('server', fn ($q) => $q->where('status', Server::STATUS_READY))
            ->with('server')
            ->chunkById(200, function ($sites) use (&$dispatched): void {
                foreach ($sites as $site) {
                    // PHP-side gate: pool engine (nginx/caddy) + SSH capability —
                    // both are model logic the query can't express. The scanner
                    // re-checks and no-ops defensively, but skipping here avoids
                    // queuing a job that would do nothing.
                    if (! $site->usesDedicatedPhpFpmPool()) {
                        continue;
                    }
                    if (! $site->server?->hostCapabilities()->supportsSsh()) {
                        continue;
                    }

                    SweepSiteHttpErrorsJob::dispatch((string) $site->id);
                    $dispatched++;
                }
            });

        $this->components->info("Dispatched {$dispatched} site 5xx sweep(s).");

        return self::SUCCESS;
    }
}
