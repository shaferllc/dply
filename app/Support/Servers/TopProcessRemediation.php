<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Turns a top-CPU process name (from the metrics agent's `top_cpu` payload) into
 * a plain-language "here's likely why, and what to do" hint. Best-effort, by
 * substring match on the process command — returns null when we have no specific
 * guidance (the UI then just shows the raw process).
 */
final class TopProcessRemediation
{
    /**
     * Ordered needle => hint. First case-insensitive substring match wins, so
     * put more specific names before generic ones.
     *
     * @var array<string, string>
     */
    private const HINTS = [
        'php-fpm' => 'PHP-FPM workers are pegged — usually slow requests or too few workers. Check the site’s Runtime → PHP pool sizing and the slow-request log.',
        'octane' => 'Laravel Octane workers are busy — app-level CPU. Profile the hot path or add workers/CPU.',
        'horizon' => 'Horizon queue workers are busy — likely a heavy or looping job. Check Workers + failed/long-running jobs.',
        'queue' => 'Queue workers are busy — a heavy or stuck job. Review the queue + failed jobs.',
        'mysqld' => 'MySQL is CPU-bound — almost always slow queries / missing indexes. Check the slow-query log.',
        'mariadb' => 'MariaDB is CPU-bound — slow queries / missing indexes. Check the slow-query log.',
        'postgres' => 'Postgres is CPU-bound — slow queries / missing indexes. Inspect pg_stat_activity for long-running statements.',
        'postmaster' => 'Postgres is CPU-bound — slow queries / missing indexes. Inspect pg_stat_activity.',
        'redis' => 'Redis is busy — expensive commands or a large keyspace op. Check the slowlog.',
        'nginx' => 'nginx is busy — high request volume or large transfers. Check access logs and consider rate limiting / caching.',
        'caddy' => 'Caddy is busy — high request volume or large transfers. Check access logs and consider rate limiting / caching.',
        'apache' => 'Apache is busy — high request volume. Check access logs and worker/MPM settings.',
        'node' => 'A Node process is CPU-bound — application work. Profile the app or scale out.',
        'bun' => 'A Bun process is CPU-bound — application work. Profile the app or scale out.',
        'python' => 'A Python process is CPU-bound — application or a script. Identify the script (PID) and profile it.',
        'unattended-upgr' => 'Unattended security upgrades are running — transient. Let it finish; it backs off on its own.',
        'apt' => 'A package manager (apt/dpkg) is running — usually transient (install/upgrade). Let it finish.',
        'dpkg' => 'A package manager (apt/dpkg) is running — usually transient. Let it finish.',
        'composer' => 'A Composer install/build is running — transient, typically during a deploy.',
        'npm' => 'An npm/asset build is running — transient, typically during a deploy.',
        'vite' => 'An asset build (Vite) is running — transient, typically during a deploy.',
        'mysqldump' => 'A database dump is running — transient (a backup). Expect it to finish.',
        'pg_dump' => 'A database dump is running — transient (a backup). Expect it to finish.',
        'rsync' => 'An rsync transfer is running — transient (backup/sync).',
        'clamd' => 'Antivirus (ClamAV) is scanning — transient/cron. Consider scheduling it off-peak.',
        'freshclam' => 'Antivirus signature update (freshclam) — transient.',
        'docker' => 'A container runtime (docker/containerd) is busy — the workload is inside a container. Inspect the container.',
        'containerd' => 'A container runtime (containerd) is busy — the workload is inside a container. Inspect the container.',
    ];

    public static function for(string $command): ?string
    {
        $needle = strtolower(trim($command));
        if ($needle === '') {
            return null;
        }

        foreach (self::HINTS as $name => $hint) {
            if (str_contains($needle, $name)) {
                return $hint;
            }
        }

        return null;
    }
}
