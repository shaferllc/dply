<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

/**
 * Whether the job store actually lives apart from the control plane.
 *
 * `config/database.php` defines a `dply_queue` connection, but every key falls
 * through to the primary `DB_*` when the `DPLY_QUEUE_DB_*` overrides are unset.
 * Nothing announces that, so the default state is a shared database that looks
 * separate everywhere in the code — the queues index
 * degrades depth to "unknown" on the premise that "the store is a separate
 * database and can be unreachable while the control plane is fine", and the
 * jobs table carries aggressive per-table autovacuum settings written for a
 * host that is not also serving the dashboard.
 *
 * Sharing is not a misconfiguration to reject — it is a perfectly reasonable
 * way to run a small install, and failing closed would take the product down
 * for people it is working for. It is a condition to *surface*, so nobody
 * discovers it from a latency graph.
 *
 * The queue table is high churn by design: every claim rewrites `visible_at`,
 * which is indexed, so no update is HOT — each one writes a new heap tuple and
 * a new index entry, and each ack deletes both. On a shared instance that
 * vacuum and WAL pressure lands on the same Postgres serving sites, servers and
 * billing.
 */
final class QueueStoreIsolation
{
    public const CONNECTION = 'dply_queue';

    /**
     * True when the queue connection resolves to a different database than the
     * primary one.
     *
     * Compared on host + port + database rather than connection name, because
     * the name is always different and tells you nothing. A `dply_queue`
     * connection pointed at the same three values IS the primary database, no
     * matter what it is called.
     */
    public static function isSeparate(): bool
    {
        $queue = self::descriptor(self::CONNECTION);
        $primary = self::descriptor((string) config('database.default'));

        return $queue !== $primary;
    }

    /**
     * A one-line description of where the store lives, for diagnostics.
     */
    public static function summary(): string
    {
        $queue = self::descriptor(self::CONNECTION);

        return self::isSeparate()
            ? $queue.' (separate from the control plane)'
            : $queue.' (SHARED with the control plane)';
    }

    /**
     * What to do about it, or null when there is nothing to say.
     */
    public static function advice(): ?string
    {
        if (self::isSeparate()) {
            return null;
        }

        return 'Job pushes and claims are hitting the same Postgres as the dashboard, sites and billing. '
            .'Point DPLY_QUEUE_DB_HOST / _PORT / _DATABASE / _USERNAME / _PASSWORD at a separate database '
            .'and run `php artisan migrate` so the queue tables are created there. '
            .'Moving it is cheapest before real traffic exists — see `php artisan dply:queue:doctor`.';
    }

    /**
     * host:port/database for a connection, resolved through the same config the
     * driver reads — including a DSN `url`, which overrides the discrete keys
     * and would otherwise make two different databases look identical.
     */
    private static function descriptor(string $connection): string
    {
        $config = (array) config('database.connections.'.$connection, []);

        $url = trim((string) ($config['url'] ?? ''));
        if ($url !== '') {
            $parts = parse_url($url);

            if (is_array($parts)) {
                return ($parts['host'] ?? '?')
                    .':'.($parts['port'] ?? '5432')
                    .'/'.ltrim((string) ($parts['path'] ?? ''), '/');
            }
        }

        return (string) ($config['host'] ?? '?')
            .':'.(string) ($config['port'] ?? '?')
            .'/'.(string) ($config['database'] ?? '?');
    }
}
