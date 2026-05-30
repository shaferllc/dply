<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use App\Services\Sites\SiteEdgeBackendProvisioner;

/**
 * Operator-tunable nginx/OpenResty globals merged into
 * {@see OpenRestyEdgeConfigBuilder} on every edge routing rebuild.
 */
class OpenRestyStaticConfigOptions
{
    use PrivilegedRemoteFileWrites;

    private const REMOTE_PATH = '/etc/openresty/nginx.conf';

    /**
     * @var array<string, array{type: string, default: string, label: string, help: string, group: string}>
     */
    public const PARAMS = [
        'worker_processes' => [
            'type' => 'string',
            'default' => 'auto',
            'group' => 'workers',
            'label' => 'worker_processes',
            'help' => 'Number of worker processes or `auto` to match CPU cores.',
        ],
        'worker_connections' => [
            'type' => 'string',
            'default' => '1024',
            'group' => 'workers',
            'label' => 'worker_connections',
            'help' => 'Max simultaneous connections per worker in the events block.',
        ],
        'client_max_body_size' => [
            'type' => 'string',
            'default' => '64m',
            'group' => 'http',
            'label' => 'client_max_body_size',
            'help' => 'Maximum allowed client request body size.',
        ],
        'proxy_read_timeout' => [
            'type' => 'string',
            'default' => '60s',
            'group' => 'http',
            'label' => 'proxy_read_timeout',
            'help' => 'Timeout for reading a response from an upstream (Caddy backend).',
        ],
        'status_port' => [
            'type' => 'string',
            'default' => '9149',
            'group' => 'admin',
            'label' => 'Status port',
            'help' => 'Localhost-only stub_status port (127.0.0.1) used by dply live-state probes.',
        ],
    ];

    /**
     * @var array<string, string>
     */
    public const PARAM_GROUPS = [
        'workers' => 'Workers',
        'http' => 'HTTP / proxy',
        'admin' => 'Observability',
    ];

    public static function installScript(): string
    {
        return <<<'BASH'
set -euo pipefail
if command -v openresty >/dev/null 2>&1 && [ -f /etc/openresty/nginx.conf ]; then
  echo "[dply] openresty already installed; skipping."
else
  apt-get install -y --no-install-recommends curl ca-certificates gnupg lsb-release
  curl -fsSL https://openresty.org/package/pubkey.gpg | gpg --batch --yes --dearmor -o /usr/share/keyrings/openresty.gpg
  CODENAME=$(lsb_release -sc 2>/dev/null || echo bookworm)
  if grep -qi debian /etc/os-release 2>/dev/null; then
    echo "deb [signed-by=/usr/share/keyrings/openresty.gpg] http://openresty.org/package/debian ${CODENAME} openresty" > /etc/apt/sources.list.d/openresty.list
  else
    echo "deb [signed-by=/usr/share/keyrings/openresty.gpg] http://openresty.org/package/ubuntu ${CODENAME} main" > /etc/apt/sources.list.d/openresty.list
  fi
  apt-get update -y
  apt-get install -y --no-install-recommends openresty
  systemctl stop openresty 2>/dev/null || true
fi
mkdir -p /etc/openresty /var/log/openresty
command -v openresty >/dev/null 2>&1 || { echo "[dply] openresty binary not on PATH after install" >&2; exit 127; }
BASH;
    }

    /**
     * @return array<string, string>
     */
    public static function defaultOperatorSettings(): array
    {
        $out = [];
        foreach (self::PARAMS as $key => $meta) {
            $out[$key] = (string) ($meta['default'] ?? '');
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function defaultForm(): array
    {
        return self::defaultOperatorSettings();
    }

    /**
     * @return array<string, string>
     */
    public static function operatorSettingsFromServer(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $stored = $meta['openresty_operator_settings'] ?? null;
        if (! is_array($stored)) {
            return self::defaultOperatorSettings();
        }

        $out = self::defaultOperatorSettings();
        foreach (self::PARAMS as $key => $meta) {
            if (array_key_exists($key, $stored)) {
                $out[$key] = trim((string) $stored[$key]);
            }
        }

        return $out;
    }

    /**
     * @return array{values: array<string, string>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        return [
            'values' => self::operatorSettingsFromServer($server),
            'unreadable' => false,
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function save(Server $server, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $normalized = self::defaultOperatorSettings();
        foreach (self::PARAMS as $key => $meta) {
            $normalized[$key] = trim((string) ($values[$key] ?? $meta['default'] ?? ''));
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['openresty_operator_settings'] = $normalized;
        $server->forceFill(['meta' => $meta])->save();

        app(SiteEdgeBackendProvisioner::class)->syncAllForServer($server, $emitter);
    }

    public function repair(Server $server, ?ConsoleEmitter $emitter = null): void
    {
        $ssh = new SshConnection($server);
        $out = $ssh->exec($this->privilegedCommand($server, 'openresty -t 2>&1'), 30);
        $exit = $ssh->lastExecExitCode();
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('OpenResty config test failed: '.trim($out));
        }
        $reload = $ssh->exec($this->privilegedCommand(
            $server,
            '(systemctl reload openresty 2>/dev/null || systemctl restart openresty) 2>&1',
        ), 60);
        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('OpenResty reload failed: '.trim($reload));
        }
    }
}
