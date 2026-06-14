<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunRoadmapAiUpdateJob;
use App\Models\RoadmapAiRun;
use App\Services\Roadmap\RoadmapAiUpdater;
use Illuminate\Console\Command;

/**
 * Drives the post-deploy AI roadmap update. Intended to be invoked with --sync on
 * the web box after a release swap, so the run is deterministic and
 * self-contained (no dependency on a worker draining the queue). Wire it into the
 * deploy engine's post-deploy hook, schedule it, or dispatch it onto the queue —
 * the retired deploy.sh shell deployer used to call it inline.
 *
 *   php artisan dply:roadmap:ai-update --sync                    (run now, in-process)
 *   php artisan dply:roadmap:ai-update --sync --commit=<sha>     (pin the target commit)
 *   php artisan dply:roadmap:ai-update                           (dispatch onto the queue)
 *
 * No-ops (records a "skipped" run) unless config roadmap.ai.enabled is true AND
 * the LLM in config/dply_ai.php is configured — safe to wire into every deploy.
 */
class RoadmapAiUpdateCommand extends Command
{
    protected $signature = 'dply:roadmap:ai-update
                            {--sync : Run synchronously in-process instead of dispatching a queued job}
                            {--commit= : Target commit SHA that was just deployed (defaults to repo HEAD)}';

    protected $description = 'Auto-update the roadmap from recent commits, suggestions, and docs using AI.';

    public function handle(RoadmapAiUpdater $updater): int
    {
        $commit = ($c = (string) $this->option('commit')) !== '' ? $c : null;

        if (! (bool) config('roadmap.ai.enabled', false)) {
            $this->line('Roadmap AI update is disabled (set ROADMAP_AI_ENABLED=true to enable). Nothing to do.');
        }

        if (! $this->option('sync')) {
            RunRoadmapAiUpdateJob::dispatch($commit);
            $this->info('Roadmap AI update dispatched onto the queue.');

            return self::SUCCESS;
        }

        $run = $updater->run($commit);

        $this->table(['field', 'value'], [
            ['status', $run->status],
            ['range', ($run->from_commit ? substr($run->from_commit, 0, 8) : '—').' .. '.($run->to_commit ? substr($run->to_commit, 0, 8) : '—')],
            ['commits considered', (string) $run->commits_considered],
            ['items shipped', (string) $run->items_shipped],
            ['items created', (string) $run->items_created],
            ['suggestions triaged', (string) $run->suggestions_triaged],
            ['summaries updated', (string) $run->summaries_updated],
            ['note', (string) $run->note],
        ]);

        // Best-effort surface: never fail a deploy on a skipped/failed AI run.
        return $run->status === RoadmapAiRun::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }
}
