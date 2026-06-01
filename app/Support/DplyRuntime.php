<?php

declare(strict_types=1);

namespace App\Support;

final class DplyRuntime
{
    public const MODE_ALL = 'all';

    public const MODE_WEB = 'web';

    public const MODE_WORKER = 'worker';

    public const WORKER_ROLE_PRIMARY = 'primary';

    public const WORKER_ROLE_REPLICA = 'replica';

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('dply_runtime.mode', self::MODE_ALL)));

        return in_array($mode, [self::MODE_ALL, self::MODE_WEB, self::MODE_WORKER], true)
            ? $mode
            : self::MODE_ALL;
    }

    public static function workerRole(): string
    {
        $role = strtolower(trim((string) config('dply_runtime.worker_role', self::WORKER_ROLE_PRIMARY)));

        return in_array($role, [self::WORKER_ROLE_PRIMARY, self::WORKER_ROLE_REPLICA], true)
            ? $role
            : self::WORKER_ROLE_PRIMARY;
    }

    public static function isSplitDeployment(): bool
    {
        return self::mode() !== self::MODE_ALL;
    }

    public static function runsScheduler(): bool
    {
        return match (self::mode()) {
            self::MODE_ALL => true,
            self::MODE_WORKER => self::workerRole() === self::WORKER_ROLE_PRIMARY,
            default => false,
        };
    }

    public static function expectsHorizon(): bool
    {
        return in_array(self::mode(), [self::MODE_ALL, self::MODE_WORKER], true);
    }

    public static function expectsReverb(): bool
    {
        return in_array(self::mode(), [self::MODE_ALL, self::MODE_WEB], true);
    }

    /**
     * @return list<string>
     */
    public static function configurationIssues(): array
    {
        $issues = [];

        if (! self::isSplitDeployment()) {
            return $issues;
        }

        if ((string) config('queue.default') !== 'redis') {
            $issues[] = 'QUEUE_CONNECTION must be redis when DPLY_RUNTIME is web or worker.';
        }

        if (self::runsScheduler() && (string) config('cache.default') !== 'redis') {
            $issues[] = 'CACHE_STORE=redis is required on the primary worker so Schedule::onOneServer() mutexes across deploys.';
        }

        if (self::mode() === self::MODE_WORKER && self::workerRole() === self::WORKER_ROLE_REPLICA) {
            return $issues;
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    public static function aboutPayload(): array
    {
        return [
            'mode' => self::mode(),
            'worker_role' => self::mode() === self::MODE_WORKER ? self::workerRole() : null,
            'runs_scheduler' => self::runsScheduler(),
            'expects_horizon' => self::expectsHorizon(),
            'expects_reverb' => self::expectsReverb(),
            'configuration_issues' => self::configurationIssues(),
        ];
    }
}
