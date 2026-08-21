<?php

declare(strict_types=1);

namespace App\Modules\Queue\Console;

use App\Modules\Queue\Support\QueueEndpoint;
use App\Modules\Queue\Support\QueueStoreIsolation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-shot readiness check for the managed Queue resource — the kill switch,
 * the public endpoint, store isolation, the schema, and the table health that
 * decides whether claim latency stays flat under load.
 *
 *   dply:queue:doctor [--json]
 *
 * Mirrors the shape of the Realtime doctor (dply:realtime:doctor) so a cutover
 * for either managed resource is the same green/red readout. Deliberately named
 * in prose rather than {@see}'d: an import for a doc reference would make this
 * module depend on Realtime for nothing.
 */
class QueueDoctorCommand extends Command
{
    protected $signature = 'dply:queue:doctor {--json : Output JSON}';

    protected $description = 'Validate the managed Queue resource (kill switch, endpoint, store isolation, schema, table health).';

    public function handle(): int
    {
        $report = $this->compileReport();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHuman($report);

        return ($report['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function compileReport(): array
    {
        $enabled = (bool) config('queue_service.enabled', false);
        $endpoint = QueueEndpoint::base();

        $checks = [
            $this->checkKillSwitch($enabled),
            $this->checkEndpoint($endpoint),
            $this->checkStoreIsolation(),
            $this->checkSchema(),
            $this->checkTableHealth(),
            $this->checkDrainCeiling(),
        ];

        $failed = array_values(array_filter($checks, fn (array $c): bool => ($c['ok'] ?? false) === false));

        return [
            'ok' => $failed === [],
            'message' => $failed === [] ? 'Queue resource looks ready.' : 'One or more checks failed.',
            'summary' => [
                'enabled' => $enabled ? 'yes' : 'no',
                'endpoint' => $endpoint !== '' ? $endpoint : '(unset)',
                'store' => QueueStoreIsolation::summary(),
                'billing' => config('queue_service.billing.enabled') ? 'live' : 'dark (free beta)',
                'default_tier' => (string) config('queue_service.default_tier', 'standard'),
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkKillSwitch(bool $enabled): array
    {
        return [
            'name' => 'kill_switch',
            'ok' => $enabled,
            'detail' => $enabled
                ? 'DPLY_QUEUE_ENABLED is on.'
                : 'DPLY_QUEUE_ENABLED is off — namespaces cannot be created and deploys will not wire queues.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkEndpoint(string $endpoint): array
    {
        return [
            'name' => 'public_endpoint',
            'ok' => $endpoint !== '',
            // A customer's worker resolves this from the open internet, so a
            // .test or localhost value is worse than none: it looks configured.
            'detail' => $endpoint !== ''
                ? 'Customers post to '.$endpoint.'.'
                : 'Set DPLY_QUEUE_PUBLIC_URL (or DPLY_PUBLIC_APP_URL). Without a publicly reachable URL, any queue created here is unreachable.',
        ];
    }

    /**
     * The check this command mainly exists for.
     *
     * Not fatal — sharing is a legitimate way to run a small install — but it
     * is reported as a failure because the surrounding code is written as
     * though it is not true, and that gap should be a deliberate choice rather
     * than a default nobody noticed.
     *
     * @return array<string, mixed>
     */
    private function checkStoreIsolation(): array
    {
        $separate = QueueStoreIsolation::isSeparate();

        return [
            'name' => 'store_isolation',
            'ok' => $separate,
            'detail' => $separate
                ? 'Job store is on its own database: '.QueueStoreIsolation::summary().'.'
                : (string) QueueStoreIsolation::advice(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSchema(): array
    {
        // `dply_queue_locks` is deliberately absent: the queue's own lock
        // store was retired in favour of dply Cache (docs/adr/dply-cache.md,
        // decision 8), so requiring the table here would fail every healthy
        // install.
        $required = ['dply_queue_jobs', 'dply_queue_failed_jobs'];

        try {
            $missing = [];
            foreach ($required as $table) {
                if (! DB::connection(QueueStoreIsolation::CONNECTION)->getSchemaBuilder()->hasTable($table)) {
                    $missing[] = $table;
                }
            }
        } catch (Throwable $e) {
            return [
                'name' => 'schema',
                'ok' => false,
                'detail' => 'Could not reach the queue connection — '.$e->getMessage(),
            ];
        }

        return [
            'name' => 'schema',
            'ok' => $missing === [],
            'detail' => $missing === []
                ? 'All queue tables present on the '.QueueStoreIsolation::CONNECTION.' connection.'
                : 'Missing on the queue connection: '.implode(', ', $missing).'. Run php artisan migrate.',
        ];
    }

    /**
     * Dead-tuple ratio and the per-table autovacuum settings.
     *
     * This is the Postgres-as-a-queue failure mode in one number. Every claim
     * rewrites the indexed `visible_at`, so no update can be HOT: each writes a
     * new heap tuple plus a new index entry, and each ack deletes both. If dead
     * tuples outpace autovacuum the index bloats and claim latency climbs until
     * someone vacuums by hand. The migration sets scale factors of 0.01 and
     * fillfactor 80 precisely to stop that — this verifies they survived.
     *
     * @return array<string, mixed>
     */
    private function checkTableHealth(): array
    {
        try {
            $row = DB::connection(QueueStoreIsolation::CONNECTION)->selectOne("
                SELECT n_live_tup, n_dead_tup, last_autovacuum
                  FROM pg_stat_user_tables
                 WHERE relname = 'dply_queue_jobs'
            ");

            if ($row === null) {
                return [
                    'name' => 'table_health',
                    'ok' => false,
                    'detail' => 'dply_queue_jobs has no stats row — the table is missing on this connection.',
                ];
            }

            $live = (int) ($row->n_live_tup ?? 0);
            $dead = (int) ($row->n_dead_tup ?? 0);

            $options = (array) DB::connection(QueueStoreIsolation::CONNECTION)->select("
                SELECT unnest(reloptions) AS opt FROM pg_class WHERE relname = 'dply_queue_jobs'
            ");
            $tuned = collect($options)
                ->contains(fn (object $o): bool => str_contains((string) $o->opt, 'autovacuum_vacuum_scale_factor'));

            // Ratio only means something once there are rows to compare against.
            $ratio = $live > 0 ? $dead / $live : 0.0;
            $bloated = $live > 1000 && $ratio > 0.5;

            return [
                'name' => 'table_health',
                'ok' => $tuned && ! $bloated,
                'detail' => sprintf(
                    '%d live / %d dead tuples%s. Per-table autovacuum tuning: %s.%s',
                    $live,
                    $dead,
                    $row->last_autovacuum !== null ? ', last autovacuum '.$row->last_autovacuum : ', never autovacuumed',
                    $tuned ? 'present' : 'MISSING — re-run the queue migration',
                    $bloated ? ' Dead tuples exceed half the live rows; autovacuum is not keeping up.' : '',
                ),
            ];
        } catch (Throwable $e) {
            return [
                'name' => 'table_health',
                'ok' => false,
                'detail' => 'Could not read pg_stat_user_tables — '.$e->getMessage(),
            ];
        }
    }

    /**
     * What a customer can actually drain, given the tier rate and the client.
     *
     * Reported because the number surprises people: Laravel's stock SQS driver
     * asks for one message per request and never long-polls, so every job costs
     * a receive AND a delete against the tier's allowance.
     *
     * @return array<string, mixed>
     */
    private function checkDrainCeiling(): array
    {
        $tiers = (array) config('queue_service.tiers', []);

        if ($tiers === []) {
            return ['name' => 'drain_ceiling', 'ok' => false, 'detail' => 'No capacity tiers configured.'];
        }

        $lines = [];
        foreach ($tiers as $slug => $tier) {
            $rpm = max(1, (int) ($tier['requests_per_minute'] ?? 0));
            $lines[] = sprintf('%s ~%d jobs/s', (string) $slug, (int) floor($rpm / 60));
        }

        return [
            'name' => 'drain_ceiling',
            'ok' => true,
            'detail' => 'Delete-bound ceiling per tier: '.implode(', ', $lines)
                .'. Stock SQS driver halves this (a receive per job as well); the batching queue class on the'
                .' queue detail page removes the receive half.',
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $ok = (bool) ($report['ok'] ?? false);
        $message = (string) ($report['message'] ?? 'Queue doctor');
        $ok ? $this->info($message) : $this->error($message);
        $this->newLine();

        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $this->table(['Setting', 'Value'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        if ($checks !== []) {
            $this->newLine();
            $this->table(
                ['Check', 'OK', 'Detail'],
                array_map(fn (array $c): array => [
                    (string) ($c['name'] ?? ''),
                    ($c['ok'] ?? false) ? 'yes' : 'no',
                    (string) ($c['detail'] ?? ''),
                ], $checks),
            );
        }
    }
}
