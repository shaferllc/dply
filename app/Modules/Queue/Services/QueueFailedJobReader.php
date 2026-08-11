<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Http\Controllers\QueueFailedJobController;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Models\QueueNamespaceUsageDaily;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard-side reads over the dply Queue data plane: failed jobs and the
 * observational throughput series.
 *
 * The HTTP surface ({@see QueueFailedJobController})
 * serves the customer's *application*, signed per-request with a namespace
 * credential. This serves the customer's *browser*, already authorized by
 * policy. Same tables, different callers — going through HTTP from Livewire
 * would mean minting a credential to talk to ourselves.
 */
final class QueueFailedJobReader
{
    private const TABLE = 'dply_queue_failed_jobs';

    private const MAX_LIST = 100;

    public function __construct(private readonly QueueStore $store) {}

    /**
     * Newest-first failed jobs for a namespace.
     *
     * Returned as a plain list rather than a Collection: this is read-only
     * display data, the caller has no use for Collection semantics, and a
     * precisely-shaped list is something PHPStan can actually verify (a
     * Collection's value template is invariant, so the shape would have to be
     * widened to `mixed` to typecheck).
     *
     * @return list<array{
     *     id: string,
     *     uuid: ?string,
     *     queue: string,
     *     name: string,
     *     attempts: int,
     *     exception: string,
     *     exception_summary: string,
     *     payload: string,
     *     failed_at: ?Carbon,
     *     retried_at: ?Carbon,
     * }>
     */
    public function recent(QueueNamespace $namespace, int $limit = 50): array
    {
        return $this->table()
            ->where('namespace_id', $namespace->id)
            ->orderByDesc('failed_at')
            ->limit(max(1, min(self::MAX_LIST, $limit)))
            ->get()
            ->map(fn (object $row) => [
                'id' => (string) $row->id,
                'uuid' => $row->job_uuid === null ? null : (string) $row->job_uuid,
                'queue' => (string) $row->queue,
                'name' => is_string($row->display_name) && $row->display_name !== ''
                    ? $row->display_name
                    : $this->nameFromPayload((string) $row->payload),
                'attempts' => (int) $row->attempts,
                'exception' => (string) ($row->exception ?? ''),
                // First line only for the list; the modal shows the whole trace.
                'exception_summary' => $this->firstLine((string) ($row->exception ?? '')),
                'payload' => (string) $row->payload,
                'failed_at' => $row->failed_at === null ? null : Carbon::parse($row->failed_at),
                'retried_at' => $row->retried_at === null ? null : Carbon::parse($row->retried_at),
            ])
            ->values()
            ->all();
    }

    /**
     * Push a failed job's payload back onto its queue and mark it retried.
     *
     * The row is kept rather than deleted: "this failed and we retried it" is
     * more useful history than a gap, and it is what `retried_at` is for.
     * Returns false when the row is gone or already retried, so a double-click
     * cannot enqueue the same job twice.
     */
    public function retry(QueueNamespace $namespace, string $id): bool
    {
        $row = $this->table()
            ->where('namespace_id', $namespace->id)
            ->where('id', $id)
            ->whereNull('retried_at')
            ->first();

        if ($row === null) {
            return false;
        }

        $this->store->push($namespace, (string) $row->queue, (string) $row->payload);

        // Stamped after the push, not before: if the push throws, the job stays
        // retryable rather than being marked done having never been enqueued.
        $this->table()
            ->where('namespace_id', $namespace->id)
            ->where('id', $id)
            ->update(['retried_at' => now()]);

        return true;
    }

    public function forget(QueueNamespace $namespace, string $id): void
    {
        $this->table()
            ->where('namespace_id', $namespace->id)
            ->where('id', $id)
            ->delete();
    }

    /**
     * How many failures are still outstanding for a namespace.
     *
     * Counts only jobs that have not been retried: a retried failure is
     * resolved history, and folding it in would leave the index permanently
     * reporting a problem the operator has already dealt with.
     */
    public function outstandingCount(QueueNamespace $namespace): int
    {
        return $this->table()
            ->where('namespace_id', $namespace->id)
            ->whereNull('retried_at')
            ->count();
    }

    /**
     * Jobs pushed per day for the trailing window, oldest first, with empty
     * days filled in so a sparkline has an even x-axis.
     *
     * Observational only — nothing is invoiced from this
     * (docs/adr/managed-services-tier.md, decision 6).
     *
     * @return list<array{date: string, jobs: int}>
     */
    public function dailyThroughput(QueueNamespace $namespace, int $days = 30): array
    {
        $since = now()->startOfDay()->subDays(max(1, $days) - 1);

        $rows = QueueNamespaceUsageDaily::query()
            ->where('namespace_id', $namespace->id)
            ->where('usage_date', '>=', $since->toDateString())
            ->pluck('jobs_pushed', 'usage_date');

        $series = [];
        for ($cursor = $since->copy(); $cursor <= now()->startOfDay(); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $series[] = [
                'date' => $key,
                'jobs' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }

    private function table(): Builder
    {
        return DB::connection('dply_queue')->table(self::TABLE);
    }

    /** Best-effort job class from the envelope, when display_name is absent. */
    private function nameFromPayload(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded) && is_string($decoded['displayName'] ?? null)) {
            return $decoded['displayName'];
        }

        return __('Unknown job');
    }

    private function firstLine(string $exception): string
    {
        $line = strtok($exception, "\n");

        return $line === false ? '' : trim($line);
    }
}
