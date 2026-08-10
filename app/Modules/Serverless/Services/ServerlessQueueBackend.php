<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Queue\Services\ServerlessQueueProvisioner;
use App\Services\Sites\DotEnvFileParser;

/**
 * Decides whether a function's queue connection can actually work.
 *
 * On a function there is no long-running process, so dply drains queues with
 * several concurrent invocations. That makes the backing store the deciding
 * factor: it has to be reachable from every container at once. Two very
 * common configurations are not, and both fail silently:
 *
 *  - `sync` (and unset, which the injected handler defaults to `sync`) runs
 *    jobs inline at dispatch. Nothing is ever enqueued, so every drain
 *    reports zero and the pump looks perfectly healthy while idle.
 *
 *  - `database` on SQLite writes to a file. The action filesystem is
 *    read-only except /tmp, and /tmp is per-container — so concurrent drains
 *    each see a different database and enqueued jobs are simply lost.
 *
 * Neither errors. Neither logs. Both look like "my jobs never run."
 *
 * This is the single place that judgement lives: the pump refuses to open
 * slots on an unusable backend, the Workers panel explains why, and the queue
 * doctor reports it — all from this classification, so they cannot drift into
 * disagreeing about whether a given site is healthy.
 */
final class ServerlessQueueBackend
{
    /** Reachable from every concurrent invocation — the pump can drain it. */
    public const STATE_SHAREABLE = 'shareable';

    /** Jobs run inline and are never enqueued (`sync`, or unset). */
    public const STATE_INERT = 'inert';

    /** A real queue driver whose store is per-container — jobs get lost. */
    public const STATE_UNSHARED = 'unshared';

    /** Some other driver dply cannot vouch for either way. */
    public const STATE_UNKNOWN = 'unknown';

    /**
     * Drivers whose store is always reachable from every container.
     *
     * `dply` is the managed queue — a connection dply provisions and the
     * injected handler registers, so it is shareable by construction.
     */
    private const SHAREABLE_DRIVERS = ['dply', 'redis', 'sqs', 'beanstalkd'];

    /** Database drivers that are a real network service. */
    private const NETWORKED_DATABASES = ['mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    public function __construct(
        private readonly ServerlessEnvironmentPreparer $environment,
        private readonly ServerlessQueueProvisioner $provisioner,
    ) {}

    /**
     * Classify the site's queue backend.
     *
     * @return array{connection: ?string, database: ?string, state: string, reason: ?string, fixable_with_redis: bool}
     */
    public function classify(Site $site): array
    {
        $env = $this->env($site);
        $connection = $this->value($env, 'QUEUE_CONNECTION');
        $database = $this->value($env, 'DB_CONNECTION');
        $redisReady = $this->redisAvailable($site);

        if ($connection === null || $connection === 'sync') {
            return [
                'connection' => $connection,
                'database' => $database,
                'state' => self::STATE_INERT,
                'reason' => $connection === null
                    ? 'QUEUE_CONNECTION is not set, so the function defaults to `sync`: jobs run inline at dispatch and nothing is ever queued.'
                    : 'QUEUE_CONNECTION is `sync`: jobs run inline at dispatch and nothing is ever queued.',
                'fixable_with_redis' => $redisReady,
            ];
        }

        if (in_array($connection, self::SHAREABLE_DRIVERS, true)) {
            return [
                'connection' => $connection,
                'database' => $database,
                'state' => self::STATE_SHAREABLE,
                'reason' => null,
                'fixable_with_redis' => false,
            ];
        }

        if ($connection === 'database') {
            if ($database !== null && in_array($database, self::NETWORKED_DATABASES, true)) {
                return [
                    'connection' => $connection,
                    'database' => $database,
                    'state' => self::STATE_SHAREABLE,
                    'reason' => null,
                    'fixable_with_redis' => false,
                ];
            }

            if ($database === 'sqlite' || $database === null) {
                return [
                    'connection' => $connection,
                    'database' => $database,
                    'state' => self::STATE_UNSHARED,
                    'reason' => 'The database queue is backed by SQLite. A function\'s filesystem is read-only except /tmp, and /tmp is per-container, so concurrent drains each see a different database and enqueued jobs are lost.',
                    'fixable_with_redis' => $redisReady,
                ];
            }
        }

        return [
            'connection' => $connection,
            'database' => $database,
            'state' => self::STATE_UNKNOWN,
            'reason' => 'dply cannot tell whether the `'.$connection.'` queue driver is reachable from every concurrent invocation. If its store is local to the container, jobs will be lost.',
            'fixable_with_redis' => $redisReady,
        ];
    }

    /**
     * Whether the pump may drain this site at all.
     *
     * `unknown` is allowed through: dply does not recognise the driver, but a
     * custom driver backed by a real service is perfectly valid and refusing
     * it would be worse than the risk. Only the two states we can prove are
     * broken are blocked.
     */
    public function canDrain(Site $site): bool
    {
        return ! in_array(
            $this->classify($site)['state'],
            [self::STATE_INERT, self::STATE_UNSHARED],
            true,
        );
    }

    /** Whether a provisioned Redis is online and usable for the queue. */
    public function redisAvailable(Site $site): bool
    {
        $cache = $site->serverlessConfig()['cache'] ?? null;

        if (! is_array($cache) || ($cache['status'] ?? '') !== 'online') {
            return false;
        }

        // The cache job writes these when the cluster comes online; without
        // them the app has nothing to connect with.
        $env = $this->env($site);

        return $this->value($env, 'REDIS_HOST') !== null || $this->value($env, 'REDIS_URL') !== null;
    }

    /**
     * Point the queue at the provisioned Redis.
     *
     * Only ever called for a backend that cannot work as configured, so this
     * never overrides a deliberate, working choice — see
     * {@see adoptRedisIfBroken()}.
     */
    public function wireRedis(Site $site): bool
    {
        if (! $this->redisAvailable($site)) {
            return false;
        }

        $this->environment->mergeKeys($site, ['QUEUE_CONNECTION' => 'redis']);

        return true;
    }

    /** Whether this backend is one dply knows cannot work here. */
    public function isBroken(Site $site): bool
    {
        return in_array(
            $this->classify($site)['state'],
            [self::STATE_INERT, self::STATE_UNSHARED],
            true,
        );
    }

    /**
     * Adopt the provisioned Redis for the queue, but only when the current
     * backend is provably broken.
     *
     * Deliberately conservative: an operator who has pointed the queue at
     * their own SQS or Redis has made a choice, and silently repointing it at
     * ours would be a worse failure than the one being fixed. A `sync`/SQLite
     * backend is not a choice — it is the default, and it does not work here.
     */
    public function adoptRedisIfBroken(Site $site): bool
    {
        if (! $this->isBroken($site)) {
            return false;
        }

        return $this->wireRedis($site);
    }

    /**
     * Repair a broken queue backend with whatever is available, preferring
     * dply Queue.
     *
     * This is the product's entry point. Before dply Queue existed the only
     * remedy on offer was "go provision a managed Redis", which meant every
     * serverless Laravel app needed a paid database before its queue worked at
     * all. A namespace is created synchronously and is usable immediately, so
     * it is both the better answer and the faster one.
     *
     * Redis remains the fallback for an org that cannot have dply Queue (plan
     * gating, or the surface flag off) but already has a cluster online.
     *
     * @return 'dply'|'redis'|null what it was repaired with, or null
     */
    public function repairIfBroken(Site $site): ?string
    {
        if (! $this->isBroken($site)) {
            return null;
        }

        if ($this->provisioner->wire($site)) {
            return 'dply';
        }

        return $this->wireRedis($site) ? 'redis' : null;
    }

    /**
     * @return array<string, string>
     */
    private function env(Site $site): array
    {
        $parsed = (new DotEnvFileParser)->parse((string) $site->env_file_content);

        return is_array($parsed['variables'] ?? null) ? $parsed['variables'] : [];
    }

    /**
     * @param  array<string, string>  $env
     */
    private function value(array $env, string $key): ?string
    {
        $value = trim((string) ($env[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
