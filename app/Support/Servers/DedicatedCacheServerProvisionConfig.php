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
        if (! is_array($meta)) {
            return self::localhostOnly($engine);
        }

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
     */
    public static function isAllowedSourceCidr(string $source): bool
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

        return match ($engine) {
            'valkey' => implode("\n", array_filter([
                "bind {$bind}",
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
                'protected-mode yes',
                'maxmemory 256mb',
                'maxmemory-policy allkeys-lru',
                'port 6379',
                $authLine,
            ]))."\n",
            default => implode("\n", array_filter([
                "bind {$bind}",
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
        $source = escapeshellarg($this->allowedFrom);

        return ["ufw allow from {$source} to any port {$port} proto tcp"];
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
