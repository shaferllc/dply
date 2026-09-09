<?php

declare(strict_types=1);

namespace App\Livewire\Backups\Concerns;

use App\Models\BackupSchedule;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The read-side summaries every Backups type tab renders above its rows: when
 * each schedule fires next, two weeks of run history, and the recent artifact
 * sizes behind each row's sparkline.
 *
 * Shared for the same reason as {@see RunsBackupSchedules} — three tabs each
 * growing their own copy is what produced two schedule tables in the first
 * place (docs/adr/backups-as-a-product.md, decision 8). Every backup model
 * uses the same 'completed'/'failed' status vocabulary, so these take a query
 * builder rather than caring which model they are counting.
 */
trait SummarisesBackupRuns
{
    /**
     * When each active schedule fires next, derived from its cron expression.
     *
     * `backup_schedules` stores no `next_run_at` — the dispatcher evaluates the
     * expression every minute — so this is computed for display only. A
     * malformed expression is a display problem, not a page-breaking one.
     *
     * @param  Collection<int, BackupSchedule>  $schedules
     * @return array<string, ?Carbon>
     */
    protected function nextRuns(Collection $schedules): array
    {
        $next = [];

        foreach ($schedules as $schedule) {
            if (! $schedule->is_active || blank($schedule->cron_expression)) {
                $next[$schedule->id] = null;

                continue;
            }

            try {
                $next[$schedule->id] = Carbon::instance(
                    (new CronExpression($schedule->cron_expression))->getNextRunDate(),
                );
            } catch (Throwable) {
                $next[$schedule->id] = null;
            }
        }

        return $next;
    }

    /**
     * Completed/failed run counts per day, zero-filled so the console strip
     * always renders a full window regardless of how sparse the data is.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return list<array{date: Carbon, completed: int, failed: int}>
     */
    protected function dailyActivity(Builder $query, int $days = 14): array
    {
        // toBase(): these rows are aggregates, not models — hydrating them into
        // the backup model would be a lie.
        $counts = $query
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('date(created_at) as day, status, count(*) as total')
            ->groupBy('day', 'status')
            ->toBase()
            ->get()
            ->reduce(function (array $carry, $row): array {
                $carry[(string) $row->day][(string) $row->status] = (int) $row->total;

                return $carry;
            }, []);

        $activity = [];
        for ($ago = $days - 1; $ago >= 0; $ago--) {
            $date = now()->subDays($ago)->startOfDay();
            $key = $date->toDateString();

            $activity[] = [
                'date' => $date,
                'completed' => $counts[$key]['completed'] ?? 0,
                'failed' => $counts[$key]['failed'] ?? 0,
            ];
        }

        return $activity;
    }

    /**
     * The last few completed artifact sizes per target, oldest first — the raw
     * material for each row's sparkline. An artifact that suddenly halves in
     * size is the cheapest early warning that something upstream broke.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $groupColumn  the foreign key identifying the target
     * @return array<string, list<int>>
     */
    protected function recentSizes(Builder $query, string $groupColumn, int $perTarget = 10): array
    {
        // toBase(): two columns of sizes, not models — hydrating a full backup
        // model per point on a sparkline would be waste and a lie about what
        // these rows are.
        return $query
            ->where('status', 'completed')
            ->where('bytes', '>', 0)
            ->orderByDesc('created_at')
            ->limit(400)
            ->toBase()
            ->get([$groupColumn, 'bytes'])
            ->groupBy(fn (object $row): string => (string) $row->{$groupColumn})
            ->map(fn (Collection $group): array => $group
                ->take($perTarget)
                ->reverse()
                ->map(fn (object $row): int => (int) $row->bytes)
                ->values()
                ->all())
            ->all();
    }
}
