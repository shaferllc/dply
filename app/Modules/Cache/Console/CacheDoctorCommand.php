<?php

declare(strict_types=1);

namespace App\Modules\Cache\Console;

use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Support\CacheEndpoint;
use App\Modules\Cache\Support\CacheStoreIsolation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-shot readiness check for the managed Cache resource.
 *
 *   dply:cache:doctor [--json]
 *
 * Mirrors the shape of the Queue doctor so a cutover for either managed
 * resource is the same green/red readout. The check this mainly exists for is
 * store isolation — see {@see CacheStoreIsolation}.
 */
class CacheDoctorCommand extends Command
{
    protected $signature = 'dply:cache:doctor {--json : Output JSON}';

    protected $description = 'Validate the managed Cache resource (kill switch, endpoint, store isolation, schema, sweep backlog).';

    public function handle(): int
    {
        $report = $this->compileReport();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
        $enabled = (bool) config('cache_service.enabled', false);
        $endpoint = CacheEndpoint::base();

        $checks = [
            $this->checkKillSwitch($enabled),
            $this->checkEndpoint($endpoint),
            $this->checkStoreIsolation(),
            $this->checkSchema(),
            $this->checkSweepBacklog(),
        ];

        $failed = array_values(array_filter($checks, fn (array $c): bool => ($c['ok'] ?? false) === false));

        return [
            'ok' => $failed === [],
            'message' => $failed === [] ? 'Cache resource looks ready.' : 'One or more checks failed.',
            'summary' => [
                'enabled' => $enabled ? 'yes' : 'no',
                'endpoint' => $endpoint !== '' ? $endpoint : '(unset)',
                'store' => CacheStoreIsolation::summary(),
                'caches' => ManagedCache::query()->count(),
                'quota_bytes' => (int) config('cache_service.shared.quota_bytes', 0),
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
                ? 'DPLY_CACHE_ENABLED is on.'
                : 'DPLY_CACHE_ENABLED is off — caches cannot be created and deploys will not wire one.',
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
            // A customer's function resolves this from the open internet, so a
            // .test or localhost value is worse than none: it looks configured.
            'detail' => $endpoint !== ''
                ? 'Customers point DYNAMODB_ENDPOINT at '.$endpoint.'.'
                : 'Set DPLY_CACHE_PUBLIC_URL (or DPLY_PUBLIC_APP_URL). Without a publicly reachable URL, any cache created here is unreachable.',
        ];
    }

    /**
     * The check this command mainly exists for.
     *
     * Not fatal — sharing is a legitimate way to run a small install — but
     * reported as a failure because the surrounding code is written as though
     * it is not true, and that gap should be a deliberate choice rather than a
     * default nobody noticed.
     *
     * @return array<string, mixed>
     */
    private function checkStoreIsolation(): array
    {
        $separate = CacheStoreIsolation::isSeparate();

        return [
            'name' => 'store_isolation',
            'ok' => $separate,
            'detail' => $separate
                ? 'Item store is on its own database: '.CacheStoreIsolation::summary().'.'
                : (string) CacheStoreIsolation::advice(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSchema(): array
    {
        try {
            $builder = DB::connection(CacheStoreIsolation::CONNECTION)->getSchemaBuilder();

            $missing = array_values(array_filter(
                ['dply_cache_items', 'dply_cache_usage'],
                fn (string $table): bool => ! $builder->hasTable($table),
            ));
        } catch (Throwable $e) {
            return [
                'name' => 'schema',
                'ok' => false,
                'detail' => 'Could not reach the cache connection — '.$e->getMessage(),
            ];
        }

        if ($missing !== []) {
            return [
                'name' => 'schema',
                'ok' => false,
                'detail' => 'Missing on the cache connection: '.implode(', ', $missing).'. Run php artisan migrate.',
            ];
        }

        // UNLOGGED is not cosmetic: it is the reason a cache's write volume
        // does not put WAL pressure on whatever database this resolves to.
        $persistence = $this->itemsPersistence();

        return [
            'name' => 'schema',
            'ok' => $persistence === 'u',
            'detail' => $persistence === 'u'
                ? 'Tables present and dply_cache_items is UNLOGGED.'
                : 'dply_cache_items is LOGGED. Every cache write is producing WAL — run: ALTER TABLE dply_cache_items SET UNLOGGED.',
        ];
    }

    /**
     * How much expired data is waiting to be reclaimed.
     *
     * A backlog is never a correctness problem — reads filter on expiry — but a
     * large one means the sweep is not running, and the quota it frees is the
     * only thing bounding the free tier.
     *
     * @return array<string, mixed>
     */
    private function checkSweepBacklog(): array
    {
        try {
            $expired = (int) DB::connection(CacheStoreIsolation::CONNECTION)
                ->table('dply_cache_items')
                ->where('expires_at', '<=', now()->getTimestamp())
                ->count();
        } catch (Throwable $e) {
            return ['name' => 'sweep_backlog', 'ok' => false, 'detail' => 'Could not read the item store — '.$e->getMessage()];
        }

        $ceiling = (int) config('cache_service.sweep.batch_size', 5_000)
            * (int) config('cache_service.sweep.max_batches', 20);

        return [
            'name' => 'sweep_backlog',
            'ok' => $expired <= $ceiling,
            'detail' => $expired <= $ceiling
                ? $expired.' expired item(s) awaiting the next sweep.'
                : $expired.' expired items — more than one sweep can clear ('.$ceiling.'). Check the scheduler is running `dply:cache:sweep`.',
        ];
    }

    private function itemsPersistence(): string
    {
        try {
            $row = DB::connection(CacheStoreIsolation::CONNECTION)
                ->selectOne("SELECT relpersistence FROM pg_class WHERE relname = 'dply_cache_items'");

            return (string) ($row->relpersistence ?? '?');
        } catch (Throwable) {
            return '?';
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        foreach ((array) ($report['summary'] ?? []) as $key => $value) {
            $this->line(sprintf('  %-14s %s', $key, (string) $value));
        }

        $this->newLine();

        foreach ((array) ($report['checks'] ?? []) as $check) {
            $ok = ($check['ok'] ?? false) === true;
            $this->line(($ok ? '<info>  ok  </info>' : '<error> fail </error>').' '.$check['name'].' — '.$check['detail']);
        }

        $this->newLine();

        ($report['ok'] ?? false)
            ? $this->info((string) $report['message'])
            : $this->error((string) $report['message']);
    }
}
