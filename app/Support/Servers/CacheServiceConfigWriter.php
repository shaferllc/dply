<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Replace the entire contents of an engine's main config file (the file
 * `CacheServiceInstallScripts::configFilePathFor()` points at), restart the
 * service, and verify it came back up. On verify-failure the previous file
 * is restored from a `/tmp` backup and the engine restarted again, so a
 * bad config never leaves the cache wedged.
 *
 * Memcached is supported too — the verify step uses `systemctl is-active`
 * since memcached has no PING wire command.
 *
 * The new content is shipped via base64 so embedded shell characters /
 * newlines / quotes / heredocs survive the SSH layer untouched.
 */
class CacheServiceConfigWriter
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * @throws \RuntimeException on SSH or verify failure (config is rolled back before throwing).
     * @throws \InvalidArgumentException on bad inputs (engine, content size, control chars).
     */
    public function write(Server $server, ServerCacheService $row, string $newContent): void
    {
        $engine = $row->engine;

        $configPath = CacheServiceInstallScripts::configFilePathFor($engine);
        $serviceName = CacheServiceInstallScripts::systemdServiceFor($engine);

        $this->guardContent($newContent);

        // Pick a verify command appropriate to the engine. Redis-family supports `PING`; memcached
        // doesn't, so we settle for "is-active" which catches the common failure modes
        // (config syntax error, port collision) — anything subtler (slow startup, half-broken
        // listener) needs operator-level inspection regardless.
        $verifyCmd = $this->verifyCommand($row);

        $b64 = base64_encode($newContent);

        $script = <<<BASH
set -e
PAYLOAD_B64={$b64}
BACKUP=/tmp/dply-cache.conf.bak.\$\$
cp -p {$configPath} \$BACKUP
printf %s "\$PAYLOAD_B64" | base64 -d > {$configPath}
chmod --reference=\$BACKUP {$configPath} 2>/dev/null || true
chown --reference=\$BACKUP {$configPath} 2>/dev/null || true
systemctl restart {$serviceName}
sleep 1
if ! ({$verifyCmd}); then
    cp -p \$BACKUP {$configPath}
    systemctl restart {$serviceName} || true
    rm -f \$BACKUP
    echo "[dply] config write failed: engine refused new config; reverted." >&2
    exit 2
fi
rm -f \$BACKUP
BASH;

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:config-write:'.$engine,
            $script,
            timeoutSeconds: 90,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException(trim($output->buffer) ?: 'Config write failed.');
        }
    }

    private function guardContent(string $content): void
    {
        $maxBytes = 256 * 1024; // 256 KB — typical cache config files are <10 KB; the cap stops a
        // surprise multi-MB paste from blowing through the SSH heredoc.
        if ($content === '') {
            throw new \InvalidArgumentException('Config content cannot be empty.');
        }
        if (strlen($content) > $maxBytes) {
            throw new \InvalidArgumentException("Config content exceeds {$maxBytes} bytes.");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content) === 1) {
            // Allow tabs (\x09), LF (\x0A), CR (\x0D). Reject other control chars — they're almost
            // always paste artefacts and would brick the engine if interpreted as config.
            throw new \InvalidArgumentException('Config contains disallowed control characters.');
        }
    }

    private function verifyCommand(ServerCacheService $row): string
    {
        if ($row->engine === 'memcached') {
            return 'systemctl is-active memcached >/dev/null 2>&1';
        }

        $cli = match ($row->engine) {
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };

        $authFlag = filled($row->auth_password ?? null)
            ? '-a '.escapeshellarg((string) $row->auth_password).' '
            : '';

        // Fall back to redis-cli for engines whose CLI tool isn't on PATH (Dragonfly typically
        // depends on the redis-cli package).
        return $authFlag.escapeshellarg($cli).' -p '.(int) $row->port.' ping >/dev/null 2>&1 || '.$authFlag.'redis-cli -p '.(int) $row->port.' ping >/dev/null 2>&1';
    }
}
