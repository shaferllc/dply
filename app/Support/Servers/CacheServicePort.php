<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Change the listen port for an existing cache instance. Mirrors the {@see CacheServiceAuth}
 * pattern: take a backup of the engine's config, remove any existing port directive, append the
 * new one, restart the systemd unit, and verify the engine accepts a connection on the new port.
 * On verify-failure the prior config is restored and the unit is restarted on the old port.
 *
 * Routes both the config path and the systemd unit through {@see CacheServiceInstallScripts} so
 * default (legacy single-instance) and named (templated) instances are handled transparently.
 */
class CacheServicePort
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    public function changePort(Server $server, ServerCacheService $row, int $newPort): void
    {
        $this->guardPort($newPort);

        if ($newPort === $row->port) {
            throw new \InvalidArgumentException('New port must differ from the current port.');
        }

        $configPath = CacheServiceInstallScripts::instanceConfigPath($row->engine, $row->name);
        $serviceUnit = CacheServiceInstallScripts::instanceServiceUnit($row->engine, $row->name);
        $script = $this->buildScript($row, $configPath, $serviceUnit, $newPort);

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:port-change:'.$row->engine,
            $script,
            timeoutSeconds: 60,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException(trim($output->buffer) ?: 'Port change command failed.');
        }
    }

    /**
     * @internal exposed for unit testing — builds the bash that the executor runs over SSH.
     */
    public function buildScript(ServerCacheService $row, string $configPath, string $serviceUnit, int $newPort): string
    {
        return match ($row->engine) {
            'redis', 'valkey', 'keydb' => $this->redisFamilyScript($row, $configPath, $serviceUnit, $newPort),
            'memcached' => $this->memcachedScript($configPath, $serviceUnit, $newPort),
            'dragonfly' => $this->dragonflyScript($configPath, $serviceUnit, $newPort),
            default => throw new \InvalidArgumentException("Unsupported cache engine: {$row->engine}"),
        };
    }

    private function redisFamilyScript(ServerCacheService $row, string $configPath, string $serviceUnit, int $newPort): string
    {
        $cli = match ($row->engine) {
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };

        // AUTH is preserved across restart because we don't touch requirepass; the verify-ping
        // therefore needs to send the password if one is set. Pass it via base64 to keep shell
        // metacharacters off the command line.
        $authProlog = '';
        $authFlag = '';
        if (filled($row->auth_password)) {
            $b64 = base64_encode($row->auth_password);
            $authProlog = "PASS_B64={$b64}\nPASS=\$(printf %s \"\$PASS_B64\" | base64 -d)\n";
            $authFlag = ' -a "$PASS"';
        }

        return <<<BASH
set -e
{$authProlog}BACKUP=/tmp/dply-cache.conf.bak.\$\$
cp -p {$configPath} \$BACKUP
sed -i.tmp '/^[[:space:]]*port[[:space:]]/d' {$configPath}
rm -f {$configPath}.tmp
printf 'port %d\\n' {$newPort} >> {$configPath}
systemctl restart {$serviceUnit}
sleep 1
if ! {$cli}{$authFlag} -p {$newPort} ping >/dev/null 2>&1; then
    cp -p \$BACKUP {$configPath}
    systemctl restart {$serviceUnit} || true
    rm -f \$BACKUP
    echo "[dply] Port change failed: engine did not respond on new port {$newPort}; reverted." >&2
    exit 2
fi
rm -f \$BACKUP
BASH;
    }

    private function memcachedScript(string $configPath, string $serviceUnit, int $newPort): string
    {
        // /etc/memcached.conf can have an active `-p NNNN`, a commented `# -p NNNN`, or no port
        // directive at all (defaults to 11211). Be robust: strip any active or commented port
        // line, then append the new one. Verification uses bash's /dev/tcp because memcached's
        // CLI surface is awkward to script and TCP-open is sufficient evidence the port moved.
        return <<<BASH
set -e
BACKUP=/tmp/dply-cache.conf.bak.\$\$
cp -p {$configPath} \$BACKUP
sed -i.tmp -E '/^[[:space:]]*#?[[:space:]]*-p[[:space:]]/d' {$configPath}
rm -f {$configPath}.tmp
printf -- '-p %d\\n' {$newPort} >> {$configPath}
systemctl restart {$serviceUnit}
sleep 1
if ! (exec 3<>/dev/tcp/127.0.0.1/{$newPort}) 2>/dev/null; then
    cp -p \$BACKUP {$configPath}
    systemctl restart {$serviceUnit} || true
    rm -f \$BACKUP
    echo "[dply] Port change failed: nothing listening on new port {$newPort}; reverted." >&2
    exit 2
fi
exec 3<&- 3>&- 2>/dev/null || true
rm -f \$BACKUP
BASH;
    }

    private function dragonflyScript(string $configPath, string $serviceUnit, int $newPort): string
    {
        return <<<BASH
set -e
BACKUP=/tmp/dply-cache.conf.bak.\$\$
cp -p {$configPath} \$BACKUP
sed -i.tmp '/^[[:space:]]*--port=/d' {$configPath}
rm -f {$configPath}.tmp
printf -- '--port=%d\\n' {$newPort} >> {$configPath}
systemctl restart {$serviceUnit}
sleep 1
if ! redis-cli -p {$newPort} ping >/dev/null 2>&1; then
    cp -p \$BACKUP {$configPath}
    systemctl restart {$serviceUnit} || true
    rm -f \$BACKUP
    echo "[dply] Port change failed: engine did not respond on new port {$newPort}; reverted." >&2
    exit 2
fi
rm -f \$BACKUP
BASH;
    }

    private function guardPort(int $port): void
    {
        if ($port < 1024 || $port > 65535) {
            throw new \InvalidArgumentException('Port must be between 1024 and 65535.');
        }
    }
}
