<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Actions\Servers\SeedProvisionedEnginesForServer;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Cache-host options chosen in the server create wizard (remote access + AUTH password).
 *
 * Stored on {@see Server::$meta} under `cache_server` and consumed by
 * {@see ServerProvisionCommandBuilder} during first-run provision,
 * then mirrored into {@see ServerCacheService} + a panel firewall rule by
 * {@see SeedProvisionedEnginesForServer}.
 */
final class DedicatedCacheServerProvisionConfig
{
    public function __construct(
        public readonly string $engine,
        public readonly bool $remoteAccess,
        public readonly string $allowedFrom,
        public readonly bool $requirePassword,
        public readonly ?string $password,
    ) {}

    public static function fromServer(?Server $server, string $engine): self
    {
        $engine = trim($engine) === '' || $engine === 'none' ? 'redis' : trim($engine);

        if ($server === null) {
            return self::localhostOnly($engine);
        }

        $meta = $server->meta ?? [];

        $cacheServer = $meta['cache_server'] ?? null;
        if (! is_array($cacheServer)) {
            return self::localhostOnly($engine);
        }

        $remoteAccess = (bool) ($cacheServer['remote_access'] ?? false);
        $allowedFrom = trim((string) ($cacheServer['allowed_from'] ?? ''));
        $requirePassword = (bool) ($cacheServer['require_password'] ?? false);
        $password = null;

        if ($requirePassword && isset($cacheServer['password_encrypted']) && is_string($cacheServer['password_encrypted'])) {
            try {
                $password = Crypt::decryptString($cacheServer['password_encrypted']);
            } catch (DecryptException) {
                $password = null;
            }
        }

        return new self($engine, $remoteAccess, $allowedFrom, $requirePassword, $password);
    }

    public static function localhostOnly(string $engine): self
    {
        return new self($engine, false, '', false, null);
    }

    public static function engineSupportsRemoteAccess(string $engine): bool
    {
        return in_array($engine, ['redis', 'valkey', 'keydb'], true);
    }

    /**
     * Reject obvious foot-guns — mirrors {@see CacheServiceNetworkExposure::guardSource()}.
     * Accepts a single CIDR/IP OR a comma-separated list of CIDRs/IPs. Returns true
     * only when EVERY part validates so a typo in one entry can't sneak past.
     */
    public static function isAllowedSourceCidr(string $source): bool
    {
        $value = trim($source);
        if ($value === '') {
            return false;
        }

        $parts = self::splitAllowedFrom($value);
        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            if (! self::isAllowedSingleSourceCidr($part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split a comma- or whitespace-separated allowlist into normalised parts.
     * The wizard accepts both delimiter styles so an operator can paste any
     * shape they have on hand. Empty entries are dropped.
     *
     * @return list<string>
     */
    public static function splitAllowedFrom(string $source): array
    {
        $tokens = preg_split('/[,\s]+/', trim($source)) ?: [];

        return array_values(array_filter(array_map('trim', $tokens), fn (string $t): bool => $t !== ''));
    }

    private static function isAllowedSingleSourceCidr(string $source): bool
    {
        $value = trim($source);
        $lower = strtolower($value);

        if ($value === '' || in_array($lower, ['any', '0.0.0.0/0', '::/0'], true)) {
            return false;
        }

        if (str_contains($value, '/')) {
            [$ip] = explode('/', $value, 2);

            return filter_var(trim($ip), FILTER_VALIDATE_IP) !== false;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public function bindDirective(): string
    {
        if ($this->remoteAccess && self::engineSupportsRemoteAccess($this->engine)) {
            return match ($this->engine) {
                'valkey', 'keydb' => '0.0.0.0 ::1',
                default => '0.0.0.0 -::1',
            };
        }

        return match ($this->engine) {
            'valkey', 'keydb' => '127.0.0.1 ::1',
            default => '127.0.0.1 -::1',
        };
    }

    public function configFileContent(string $engine): string
    {
        $engine = trim($engine) === '' || $engine === 'none' ? 'redis' : trim($engine);
        $bind = $this->bindDirective();
        $authLine = $this->requirePassLine($engine);

        // Per-engine data directory. Without an explicit `dir`, redis/valkey/keydb
        // fall back to `./` which under systemd hardening resolves to `/` —
        // unwritable, BGSAVE fails, and `stop-writes-on-bgsave-error yes`
        // (default) freezes ALL writes including SET. We hit exactly that on
        // dply-redis-1: the minimal 4-line config we generated dropped the
        // package default's `dir /var/lib/<engine>` and broke writes on the
        // first BGSAVE attempt. Fix here is to always emit it explicitly.
        //
        // `save ""` disables RDB snapshots — appropriate for a dedicated cache
        // box where the workload is volatile and persistence ergonomics aren't
        // worth the disk write loop. Operators who want persistence can flip
        // it on later via the Persistence card (Phase 3a) and the existing
        // schedule editor; the snapshot pipeline (Phase 3b) handles backups
        // explicitly and doesn't need the engine's own RDB schedule.
        //
        // `stop-writes-on-bgsave-error no` is belt-and-suspenders for the rare
        // case an operator turns RDB back on and then hits a transient disk
        // error — writes keep flowing, the engine logs the BGSAVE failure for
        // diagnosis, instead of the whole cache going read-only.
        $dataDir = match ($engine) {
            'valkey' => '/var/lib/valkey',
            'keydb' => '/var/lib/keydb',
            default => '/var/lib/redis',
        };

        return match ($engine) {
            'valkey' => implode("\n", array_filter([
                "bind {$bind}",
                "dir {$dataDir}",
                'dbfilename dump.rdb',
                'save ""',
                'stop-writes-on-bgsave-error no',
                'appendonly no',
                'maxmemory 256mb',
                'maxmemory-policy allkeys-lru',
                $authLine,
            ]))."\n",
            'memcached' => implode("\n", [
                '-d',
                'logfile /var/log/memcached.log',
                '-m 256',
                '-p 11211',
                '-l '.($this->remoteAccess ? '0.0.0.0' : '127.0.0.1'),
                '-U 0',
            ])."\n",
            'keydb' => implode("\n", array_filter([
                "bind {$bind}",
                "dir {$dataDir}",
                'dbfilename dump.rdb',
                'protected-mode yes',
                'save ""',
                'stop-writes-on-bgsave-error no',
                'appendonly no',
                'maxmemory 256mb',
                'maxmemory-policy allkeys-lru',
                'port 6379',
                $authLine,
            ]))."\n",
            default => implode("\n", array_filter([
                "bind {$bind}",
                "dir {$dataDir}",
                'dbfilename dump.rdb',
                'save ""',
                'stop-writes-on-bgsave-error no',
                'appendonly no',
                'maxmemory 256mb',
                'maxmemory-policy allkeys-lru',
                $authLine,
            ]))."\n",
        };
    }

    /**
     * @return list<string>
     */
    public function ufwAllowLines(): array
    {
        if (! $this->remoteAccess || ! self::engineSupportsRemoteAccess($this->engine)) {
            return [];
        }

        if (! self::isAllowedSourceCidr($this->allowedFrom)) {
            return [];
        }

        $port = ServerCacheService::defaultPortFor($this->engine);

        // One UFW rule per CIDR so operators can paste "home_ip, office_cidr, vpn_exit"
        // at provision time and connect from any of them. UFW dedupes inserts so a
        // re-run with the same list is idempotent.
        $lines = [];
        foreach (self::splitAllowedFrom($this->allowedFrom) as $cidr) {
            if (! self::isAllowedSingleSourceCidr($cidr)) {
                continue;
            }
            $lines[] = 'ufw allow from '.escapeshellarg($cidr).' to any port '.$port.' proto tcp';
        }

        return $lines;
    }

    private function requirePassLine(string $engine): string
    {
        if (! $this->requirePassword || $this->password === null || $this->password === '') {
            return '';
        }

        if (! ServerCacheService::engineSupportsAuth($engine)) {
            return '';
        }

        return 'requirepass '.$this->password;
    }
}
