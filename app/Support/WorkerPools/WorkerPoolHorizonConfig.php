<?php

declare(strict_types=1);

namespace App\Support\WorkerPools;

use App\Models\WorkerPool;

/**
 * The dply-managed Horizon knobs for a worker pool: which queues the workers
 * watch, how many processes, balance strategy, and per-worker memory / job
 * timeout / tries. Stored on `pool->meta['horizon_config']`; auto-defaulted from
 * the primary server's spec so a fresh pool is configured without user input.
 *
 * {@see config/horizon.php} reads the projected HORIZON_* env vars, and
 * {@see \App\Jobs\PushWorkerPoolHorizonConfigJob} writes them to each box.
 */
final class WorkerPoolHorizonConfig
{
    public const BALANCES = ['simple', 'auto', 'false'];

    /**
     * Normalised config for a pool, merging stored values over auto-defaults.
     *
     * @return array{queues: array<int, string>, max_processes: int, min_processes: int, balance: string, memory: int, timeout: int, tries: int}
     */
    public static function for(WorkerPool $pool): array
    {
        $stored = is_array($pool->meta['horizon_config'] ?? null) ? $pool->meta['horizon_config'] : [];
        $defaults = self::defaults($pool);

        $queues = self::cleanQueues($stored['queues'] ?? $defaults['queues']);
        $min = self::clampInt($stored['min_processes'] ?? $defaults['min_processes'], 1, 256);
        $max = self::clampInt($stored['max_processes'] ?? $defaults['max_processes'], $min, 256);
        $balance = in_array($stored['balance'] ?? null, self::BALANCES, true) ? $stored['balance'] : $defaults['balance'];

        return [
            'queues' => $queues !== [] ? $queues : $defaults['queues'],
            'min_processes' => $min,
            'max_processes' => $max,
            'balance' => $balance,
            'memory' => self::clampInt($stored['memory'] ?? $defaults['memory'], 32, 4096),
            'timeout' => self::clampInt($stored['timeout'] ?? $defaults['timeout'], 5, 3600),
            'tries' => self::clampInt($stored['tries'] ?? $defaults['tries'], 1, 25),
        ];
    }

    /**
     * The HORIZON_* env vars (as written to each member's .env) for a pool's
     * effective config.
     *
     * @return array<string, string>
     */
    public static function envVarsFor(WorkerPool $pool): array
    {
        $c = self::for($pool);

        return [
            'HORIZON_QUEUES' => implode(',', $c['queues']),
            // The member app runs against a Redis it may SHARE with dply's
            // control plane (which now defaults its queue to 'dply'). Pin the
            // member's dispatch queue to its own first queue (normally 'default')
            // so the member's jobs and dply's jobs never land on the same list.
            'REDIS_QUEUE' => $c['queues'][0] ?? 'default',
            'HORIZON_BALANCE' => $c['balance'],
            'HORIZON_MIN_PROCESSES' => (string) $c['min_processes'],
            'HORIZON_MAX_PROCESSES' => (string) $c['max_processes'],
            'HORIZON_WORKER_MEMORY' => (string) $c['memory'],
            'HORIZON_JOB_TIMEOUT' => (string) $c['timeout'],
            'HORIZON_TRIES' => (string) $c['tries'],
        ];
    }

    /**
     * Sensible defaults so a fresh pool is configured without user input: queue
     * `default`, a small auto-balanced process pool, 128 MB workers. The user
     * tunes these from the pool's Horizon config panel.
     *
     * @return array{queues: array<int, string>, max_processes: int, min_processes: int, balance: string, memory: int, timeout: int, tries: int}
     */
    public static function defaults(WorkerPool $pool): array
    {
        return [
            'queues' => ['default'],
            'min_processes' => 1,
            'max_processes' => 4,
            'balance' => 'auto',
            'memory' => 128,
            'timeout' => 720,
            'tries' => 1,
        ];
    }

    /**
     * @param  mixed  $queues
     * @return array<int, string>
     */
    private static function cleanQueues($queues): array
    {
        if (is_string($queues)) {
            $queues = explode(',', $queues);
        }
        if (! is_array($queues)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($q) => preg_replace('/[^A-Za-z0-9_:\-.]/', '', trim((string) $q)) ?? '',
            $queues,
        ), fn ($q) => $q !== '')));
    }

    /**
     * @param  mixed  $value
     */
    private static function clampInt($value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
