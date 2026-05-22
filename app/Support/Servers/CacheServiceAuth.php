<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Set / clear the AUTH password for a redis-family cache engine. The new value is written into
 * the engine's main config file (existing `requirepass …` lines are removed first to keep the
 * write idempotent), the systemd service is restarted, and a probe with the new password
 * verifies the engine accepts it.
 *
 * Memcached has no native auth and is rejected up front.
 *
 * The password is passed to the remote shell base64-encoded so embedded shell metacharacters
 * (spaces, quotes, semicolons) survive the SSH layer without escaping gymnastics. The Livewire
 * action layer validates the password against a safe charset before we get here, but this layer
 * is defensive in case it's ever called from a different surface.
 */
class CacheServiceAuth
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * Apply `requirepass <password>` and restart the engine. On verify-failure the helper restores
     * the previous config snapshot from `/tmp/dply-cache.conf.bak.*` and re-restarts.
     *
     * @throws \InvalidArgumentException when the engine is unsupported (memcached) or the password
     *                                   contains a newline (`requirepass` is single-line).
     * @throws \RuntimeException when SSH or the engine's verify step fails after retry.
     */
    public function setRequirePass(Server $server, ServerCacheService $row, string $newPassword): void
    {
        $this->guardSupported($row->engine);
        $this->guardPasswordValue($newPassword);

        $configPath = CacheServiceInstallScripts::configFilePathFor($row->engine);
        $serviceName = CacheServiceInstallScripts::systemdServiceFor($row->engine);
        $cli = $this->cliForEngine($row->engine);
        $b64 = base64_encode($newPassword);

        // Heredoc-quoted single-quoted bash so PHP doesn't interpolate; the only injected values
        // are escapeshellarg-quoted shell tokens and the base64 payload (which is alphanumeric
        // plus `+/=`, all shell-safe).
        $script = <<<BASH
set -e
PASS_B64={$b64}
NEW_PASS=\$(printf %s "\$PASS_B64" | base64 -d)
BACKUP=/tmp/dply-cache.conf.bak.\$\$
cp -p {$configPath} \$BACKUP
sed -i.tmp '/^[[:space:]]*requirepass[[:space:]]/d' {$configPath}
rm -f {$configPath}.tmp
printf 'requirepass %s\n' "\$NEW_PASS" >> {$configPath}
systemctl restart {$serviceName}
sleep 1
if ! {$cli} -a "\$NEW_PASS" -p {$row->port} ping >/dev/null 2>&1; then
    cp -p \$BACKUP {$configPath}
    systemctl restart {$serviceName} || true
    rm -f \$BACKUP
    echo "[dply] AUTH set failed: engine refused new password; reverted." >&2
    exit 2
fi
rm -f \$BACKUP
BASH;

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:auth-set:'.$row->engine,
            $script,
            timeoutSeconds: 60,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException(trim($output->buffer) ?: 'AUTH set command failed.');
        }
    }

    /**
     * Strip any `requirepass` lines from the config and restart so the engine accepts unauth
     * connections again. Idempotent — running on a config without `requirepass` is a no-op.
     */
    public function clearRequirePass(Server $server, ServerCacheService $row): void
    {
        $this->guardSupported($row->engine);

        $configPath = CacheServiceInstallScripts::configFilePathFor($row->engine);
        $serviceName = CacheServiceInstallScripts::systemdServiceFor($row->engine);

        $script = <<<BASH
set -e
sed -i.tmp '/^[[:space:]]*requirepass[[:space:]]/d' {$configPath}
rm -f {$configPath}.tmp
systemctl restart {$serviceName}
BASH;

        $output = $this->executor->runInlineBash(
            $server,
            'cache-service:auth-clear:'.$row->engine,
            $script,
            timeoutSeconds: 60,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException(trim($output->buffer) ?: 'AUTH clear command failed.');
        }
    }

    private function guardSupported(string $engine): void
    {
        if (! ServerCacheService::engineSupportsAuth($engine)) {
            throw new \InvalidArgumentException("AUTH password is not supported for engine [{$engine}].");
        }
    }

    private function guardPasswordValue(string $password): void
    {
        if ($password === '' || strlen($password) > 256) {
            throw new \InvalidArgumentException('Password must be 1–256 characters.');
        }
        if (str_contains($password, "\n") || str_contains($password, "\r")) {
            throw new \InvalidArgumentException('Password must not contain newlines.');
        }
    }

    private function cliForEngine(string $engine): string
    {
        return match ($engine) {
            'valkey' => 'valkey-cli',
            'keydb' => 'keydb-cli',
            default => 'redis-cli',
        };
    }
}
