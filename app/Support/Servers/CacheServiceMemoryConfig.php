<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * High-level surface for the two redis-family knobs operators tune most often:
 * `maxmemory` (memory cap) and `maxmemory-policy` (eviction strategy when the cap is hit).
 *
 * Reads are cheap — a `grep` over the engine's main config file pulls both lines.
 *
 * Writes go through the same atomic backup → write → restart → verify → rollback flow as
 * the AUTH and full-config writers, so a typo never wedges the cache. The verify step uses
 * the engine's auth-aware `redis-cli ping` so a value that won't parse (e.g., maxmemory
 * `bananas`) gets rolled back rather than left half-applied.
 *
 * Memcached is rejected up front — its memory limit lives in `/etc/memcached.conf` as
 * `-m <megabytes>` (a process-launch flag) and it has no eviction policy directive, so the
 * shape doesn't fit.
 */
class CacheServiceMemoryConfig
{
    public const POLICIES = [
        'noeviction',
        'allkeys-lru',
        'allkeys-lfu',
        'allkeys-random',
        'volatile-lru',
        'volatile-lfu',
        'volatile-random',
        'volatile-ttl',
    ];

    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * @return array{maxmemory: ?string, maxmemory_policy: ?string}
     */
    public function read(Server $server, ServerCacheService $row): array
    {
        $this->guardSupported($row->engine);

        $configPath = CacheServiceInstallScripts::configFilePathFor($row->engine);

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:memory-read:'.$row->engine,
            'grep -E "^[[:space:]]*(maxmemory|maxmemory-policy)[[:space:]]" '.escapeshellarg($configPath).' || true',
            timeoutSeconds: 30,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            // `|| true` above means we should never reach this — if the SSH call itself fails
            // we'll get a thrown exception out of the executor instead of a non-zero exit.
            return ['maxmemory' => null, 'maxmemory_policy' => null];
        }

        $maxmemory = null;
        $policy = null;
        foreach (explode("\n", $output->buffer) as $line) {
            $line = trim($line);
            if (preg_match('/^maxmemory-policy\s+(\S+)/i', $line, $m) === 1) {
                $policy = strtolower($m[1]);
            } elseif (preg_match('/^maxmemory\s+(\S+)/i', $line, $m) === 1) {
                $maxmemory = strtolower($m[1]);
            }
        }

        return ['maxmemory' => $maxmemory, 'maxmemory_policy' => $policy];
    }

    /**
     * Apply (or clear) the maxmemory and maxmemory-policy directives. Pass `null` for either
     * value to remove that directive entirely (revert to engine default).
     */
    public function write(Server $server, ServerCacheService $row, ?string $maxmemory, ?string $policy): void
    {
        $this->guardSupported($row->engine);
        $this->guardValues($maxmemory, $policy);

        $configPath = CacheServiceInstallScripts::configFilePathFor($row->engine);
        $serviceName = CacheServiceInstallScripts::systemdServiceFor($row->engine);
        $verifyCmd = $this->verifyCommand($row);

        $maxmemoryAppend = $maxmemory !== null
            ? 'printf '.escapeshellarg('maxmemory '.$maxmemory."\n").' >> '.escapeshellarg($configPath)
            : '';
        $policyAppend = $policy !== null
            ? 'printf '.escapeshellarg('maxmemory-policy '.$policy."\n").' >> '.escapeshellarg($configPath)
            : '';

        $script = <<<BASH
set -e
BACKUP=/tmp/dply-cache.conf.bak.\$\$
cp -p {$configPath} \$BACKUP
sed -i.tmp -e '/^[[:space:]]*maxmemory[[:space:]]/d' -e '/^[[:space:]]*maxmemory-policy[[:space:]]/d' {$configPath}
rm -f {$configPath}.tmp
{$maxmemoryAppend}
{$policyAppend}
systemctl restart {$serviceName}
sleep 1
if ! ({$verifyCmd}); then
    cp -p \$BACKUP {$configPath}
    systemctl restart {$serviceName} || true
    rm -f \$BACKUP
    echo "[dply] memory settings write failed: engine refused new config; reverted." >&2
    exit 2
fi
rm -f \$BACKUP
BASH;

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:memory-write:'.$row->engine,
            $script,
            timeoutSeconds: 60,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException(trim($output->buffer) ?: 'Memory settings write failed.');
        }
    }

    private function guardSupported(string $engine): void
    {
        if (! ServerCacheService::engineSupportsAuth($engine)) {
            // engineSupportsAuth() doubles as a "redis-family?" check (the same set of engines
            // accept maxmemory). Memcached has its own knobs and isn't supported here.
            throw new \InvalidArgumentException("Memory settings are not supported for engine [{$engine}].");
        }
    }

    private function guardValues(?string $maxmemory, ?string $policy): void
    {
        if ($maxmemory !== null) {
            // `0` (no limit), or `<number><unit>` where unit is bytes/kb/mb/gb. Reject anything
            // else before we hand it to the shell — the engine would reject it too, just less
            // clearly.
            if (preg_match('/^(0|\d+(b|kb|mb|gb))$/i', $maxmemory) !== 1) {
                throw new \InvalidArgumentException('maxmemory must be 0 or a value like 256mb / 1gb.');
            }
        }
        if ($policy !== null && ! in_array($policy, self::POLICIES, true)) {
            throw new \InvalidArgumentException('maxmemory-policy must be one of: '.implode(', ', self::POLICIES));
        }
    }

    private function verifyCommand(ServerCacheService $row): string
    {
        $cli = match ($row->engine) {
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };

        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' '
            : '';

        return $authFlag.escapeshellarg($cli).' -p '.(int) $row->port.' ping >/dev/null 2>&1 || '.$authFlag.'redis-cli -p '.(int) $row->port.' ping >/dev/null 2>&1';
    }
}
