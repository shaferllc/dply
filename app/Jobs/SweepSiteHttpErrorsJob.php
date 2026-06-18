<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Middleware\SerializeServerSsh;
use App\Models\ErrorEvent;
use App\Models\Site;
use App\Modules\Notifications\Services\SiteErrorsNotificationDispatcher;
use App\Services\Sites\SiteHttp5xxLogScanner;
use App\Support\Errors\ErrorEventRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tier-2 of the server-error-reference feature: sweep one site's PHP-FPM access
 * log for 5xx responses and capture each as an `http_5xx` {@see ErrorEvent}.
 * Dispatched per eligible site by {@see \App\Console\Commands\SweepSiteHttpErrorsCommand}.
 *
 * Per-SERVER SSH serialization via {@see SerializeServerSsh} so a box with many
 * sites isn't hit by concurrent log reads. Capture is idempotent on the
 * reference, so the unique lock only needs to stop a backlog pile-up, not
 * guarantee exactly-once.
 */
class SweepSiteHttpErrorsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    /** Lock auto-expires so a lost run can't wedge future sweeps. */
    public int $uniqueFor = 300;

    public function __construct(public string $siteId) {}

    public function uniqueId(): string
    {
        return 'sweep-http-errors:'.$this->siteId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $serverId = Site::query()->whereKey($this->siteId)->value('server_id');

        return $serverId !== null ? [new SerializeServerSsh((string) $serverId)] : [];
    }

    public function handle(
        SiteHttp5xxLogScanner $scanner,
        ErrorEventRecorder $recorder,
        SiteErrorsNotificationDispatcher $notifier,
    ): void {
        if (! config('server_error_codes.sweep_enabled', true)) {
            return;
        }

        $site = Site::query()->with('server')->find($this->siteId);
        if ($site === null) {
            return;
        }

        $result = $scanner->scan(
            $site,
            (int) config('server_error_codes.sweep_lookback_minutes', 15),
            (int) config('server_error_codes.sweep_max_per_site', 200),
        );

        if (! $result['ok'] || $result['hits'] === []) {
            return;
        }

        if ($result['truncated']) {
            Log::warning('5xx sweep truncated to the per-site cap; older hits in the window were dropped.', [
                'site_id' => $site->id,
                'cap' => (int) config('server_error_codes.sweep_max_per_site', 200),
            ]);
        }

        // Fold notifications like the uptime streak: a site already showing an
        // un-dismissed 5xx event is mid-incident, so don't re-alert on every new
        // request's reference (a crash loop would otherwise blast one per hit).
        // The first 5xx of a fresh streak notifies; the rest stay quiet until the
        // operator dismisses or the events age out.
        $streakAlreadyOpen = ErrorEvent::query()
            ->forSite((string) $site->id)
            ->where('category', 'http_5xx')
            ->undismissed()
            ->exists();

        $newest = null;
        foreach ($result['hits'] as $hit) {
            $event = $recorder->recordHttp5xx($site, $hit);
            if ($event && $event->wasRecentlyCreated) {
                $newest ??= $event; // hits are newest-first, so the first new one is the latest
            }
        }

        if (! $streakAlreadyOpen && $newest !== null) {
            try {
                $notifier->notify($newest);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
