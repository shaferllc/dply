<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Jobs;

use App\Models\Site;
use App\Modules\Serverless\Models\ServerlessFailedJob;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use App\Modules\Serverless\Services\ServerlessQueuePump;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One pump slot: a single bounded `queue:work` drain inside the customer's
 * function, plus the decision about what to do next.
 *
 * The slot is the unit of concurrency. While it exists it holds one
 * reservation from {@see ServerlessQueuePump}. When work remains it hands
 * that reservation to a freshly dispatched successor rather than releasing
 * and re-acquiring it — otherwise a busy site would drop to zero slots
 * between drains and another wake could claim the capacity first.
 *
 * Exactly one of two things must happen on every path: the reservation is
 * handed on, or it is released. `$slotHandedOn` is what keeps those mutually
 * exclusive, including when the invoke throws.
 */
class ServerlessQueueSlotJob implements ShouldQueue
{
    use Queueable;

    /**
     * Slot invocations are long (up to the function's own timeout) and the
     * pump re-drives them, so retrying a failed slot here would double up.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $siteId) {}

    public function handle(ServerlessQueuePump $pump, InvokeFunctionTick $tick): void
    {
        $site = Site::query()->find($this->siteId);

        if ($site === null) {
            return;
        }

        $config = $pump->config($site);

        // Disabled mid-flight (or the site was torn down): release and stop.
        if (! $config['enabled']) {
            $pump->closeSlot($site);
            $pump->reset($site);

            return;
        }

        $slotHandedOn = false;

        try {
            $result = $tick->tickQueueSlot($site, [
                'queue' => $config['queue'],
                'slot_max_time' => $config['slot_max_time'],
                'slot_max_jobs' => $config['slot_max_jobs'],
            ]);

            $report = $result['report'];
            $remaining = $report['remaining'];

            // Mirror anything that failed before deciding what to do next —
            // a slot that then stops must still have recorded its failures.
            $this->recordFailures($site, $report['failures'] ?? []);

            // A slot that could not reach the function must not spin: closing
            // here means the next minute's safety-net tick retries, rather
            // than this job hot-looping against a broken function.
            if (! $report['ok'] && $report['processed'] === 0) {
                return;
            }

            // null means the handler could not count the queue. Treat it as
            // "there may be more" only when this slot actually did work —
            // otherwise an old handler that drains to empty would re-invoke
            // forever.
            $mayHaveMore = $remaining === null
                ? $report['processed'] > 0
                : $remaining > 0;

            if (! $mayHaveMore) {
                return;
            }

            // Backlog is deeper than one slot can chew through — ask for more
            // concurrency before continuing. Bounded by the ceiling inside
            // wake(), so this cannot run away.
            if ($remaining !== null && $remaining > ServerlessQueuePump::RAMP_THRESHOLD) {
                $pump->wake($site);
            }

            self::dispatch($this->siteId);
            $slotHandedOn = true;
        } catch (Throwable $e) {
            Log::warning('serverless.queue.slot_failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if (! $slotHandedOn) {
                $pump->closeSlot($site);
            }
        }
    }

    /**
     * Mirror the slot's reported failures into serverless_failed_jobs.
     *
     * Keyed on the Laravel failed-job uuid so the same failure seen twice
     * (an overlapping slot, a re-reported drain) updates one row rather than
     * duplicating. A failure with no uuid still gets recorded — it is worth
     * showing even though it cannot be retried by id.
     *
     * @param  list<array<string, mixed>>  $failures
     */
    private function recordFailures(Site $site, array $failures): void
    {
        foreach ($failures as $failure) {
            $uuid = trim((string) ($failure['uuid'] ?? ''));

            $attributes = [
                'connection_name' => $this->trimOrNull($failure['connection_name'] ?? null, 64),
                'queue' => $this->trimOrNull($failure['queue'] ?? null, 128),
                'job_class' => $this->trimOrNull($failure['job_class'] ?? null, 255),
                'exception_message' => $this->trimOrNull($failure['exception_message'] ?? null, 2000),
                'exception_excerpt' => $this->trimOrNull(
                    $failure['exception_excerpt'] ?? null,
                    ServerlessFailedJob::EXCEPTION_EXCERPT_CHARS,
                ),
                'failed_at' => $this->parseFailedAt($failure['failed_at'] ?? null),
            ];

            try {
                if ($uuid !== '') {
                    ServerlessFailedJob::query()->updateOrCreate(
                        ['site_id' => $site->id, 'uuid' => Str::limit($uuid, 64, '')],
                        $attributes,
                    );

                    continue;
                }

                ServerlessFailedJob::query()->create(
                    array_merge($attributes, ['site_id' => $site->id, 'uuid' => null]),
                );
            } catch (Throwable $e) {
                // Never let mirroring a failure fail the slot — the drain
                // itself succeeded and the capacity must still be released.
                Log::warning('serverless.queue.failure_record_failed', [
                    'site_id' => $site->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function trimOrNull(mixed $value, int $limit): ?string
    {
        $text = trim((string) (is_scalar($value) ? $value : ''));

        return $text === '' ? null : Str::limit($text, $limit, '');
    }

    private function parseFailedAt(mixed $value): CarbonImmutable
    {
        try {
            $text = trim((string) (is_scalar($value) ? $value : ''));

            return $text === '' ? CarbonImmutable::now() : CarbonImmutable::parse($text);
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }
}
