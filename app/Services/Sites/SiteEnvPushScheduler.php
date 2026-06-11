<?php

namespace App\Services\Sites;

use App\Jobs\PushSiteEnvJob;
use App\Models\ConsoleAction;
use App\Models\Site;

/**
 * Coalesces env-file SSH pushes so a burst of edits never opens more SSH
 * sessions than necessary.
 *
 * Two layers collapse a flurry of mutations into ONE push:
 *   1. In-flight check — if a push is already queued/running for the site, we
 *      ride it: {@see PushSiteEnvJob} reads the live cache when it runs, so the
 *      change is included without dispatching a second SSH job.
 *   2. Debounce delay — the dispatch is delayed a few seconds, widening the
 *      window in (1) so consecutive single edits coalesce instead of each
 *      firing its own push the instant the previous one finishes.
 *
 * {@see PushSiteEnvJob}'s per-site {@see \Illuminate\Contracts\Queue\ShouldBeUnique}
 * lock is the backstop against any double-dispatch race the checks above miss.
 *
 * Bulk operations (remove/add many at once) write the cache once and call this
 * once, so they already push a single time regardless of the debounce.
 */
class SiteEnvPushScheduler
{
    /**
     * Debounce window in seconds. Mutations within this window of an
     * already-scheduled push ride it instead of dispatching their own.
     */
    public const DEBOUNCE_SECONDS = 3;

    /**
     * Ensure exactly one env push is pending for the site.
     *
     * @return array{run: ConsoleAction, coalesced: bool} the console run the
     *   caller should watch, and whether it joined an already-pending push
     */
    public function schedule(Site $site, ?string $userId): array
    {
        $pending = ConsoleAction::query()
            ->forSubject($site)
            ->ofKind('env_push')
            ->notDismissed()
            ->inFlight()
            ->latest()
            ->first();

        if ($pending !== null) {
            return ['run' => $pending, 'coalesced' => true];
        }

        $run = $this->seedRun($site, $userId);

        PushSiteEnvJob::dispatch($site->id, $userId !== null && $userId !== '' ? $userId : null, (string) $run->id)
            ->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));

        return ['run' => $run, 'coalesced' => false];
    }

    /**
     * Seed a queued env_push console run, clearing settled/stale runs first so
     * the banner tracks only the live push. Mirrors
     * {@see \App\Livewire\Sites\Concerns\ManagesSiteEnvironment::seedQueuedConsoleAction}.
     */
    private function seedRun(Site $site, ?string $userId): ConsoleAction
    {
        ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->whereNull('dismissed_at', 'and', false)
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], 'and', false)
            ->update(['dismissed_at' => now()]);

        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->whereNull('dismissed_at', 'and', false)
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING], 'and', false)
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        return ConsoleAction::query()->create([
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'kind' => 'env_push',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => null,
            'user_id' => $userId !== null && $userId !== '' ? $userId : null,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }
}
