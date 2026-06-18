<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\SiteEdgeBackendProvisioner;
use App\Services\SshConnection;

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

    /**
     * Shared bash helpers for OpenResty apt install (policy-rc.d, placeholder
     * config that avoids binding :80, package-state checks).
     */
    public static function installShellHelpers(): string
    {
        return <<<'BASH'

openresty_pkg_configured() {
  dpkg-query -W -f='${Status}' openresty 2>/dev/null | grep -q '^install ok installed$'
}
install_openresty_policy_rc_d() {
  printf '%s\n%s\n' '#!/bin/sh' 'exit 101' > /usr/sbin/policy-rc.d
  chmod +x /usr/sbin/policy-rc.d
}
remove_openresty_policy_rc_d() {
  rm -f /usr/sbin/policy-rc.d
}
write_openresty_placeholder_config() {
  mkdir -p /var/log/openresty /usr/local/openresty/nginx/logs
  cat > /tmp/dply-openresty-nginx.conf <<'DPLY_NGINX'
# dply placeholder — replaced during edge-proxy provision/cutover
worker_processes auto;
error_log /var/log/openresty/error.log;
pid /usr/local/openresty/nginx/logs/nginx.pid;
events { worker_connections 1024; }
http {
  server {
    listen 127.0.0.1:18080 default_server;
    return 503;
  }
}
DPLY_NGINX
  TARGET=""
  if [ -f /usr/local/openresty/nginx/conf/nginx.conf ]; then
    TARGET="/usr/local/openresty/nginx/conf/nginx.conf"
  elif [ -L /etc/openresty ] && [ -f /etc/openresty/nginx.conf ]; then
    TARGET="/etc/openresty/nginx.conf"
  elif [ -d /etc/openresty ] && [ ! -L /etc/openresty ] && [ -f /etc/openresty/nginx.conf ]; then
    TARGET="/etc/openresty/nginx.conf"
  elif [ -d /usr/local/openresty/nginx/conf ]; then
    TARGET="/usr/local/openresty/nginx/conf/nginx.conf"
  fi
  if [ -n "$TARGET" ]; then
    install -D -m 0644 /tmp/dply-openresty-nginx.conf "$TARGET"
    echo "[dply] openresty placeholder config installed at ${TARGET}."
  fi
  rm -f /tmp/dply-openresty-nginx.conf
}
ensure_openresty_stopped() {
  systemctl stop openresty 2>/dev/null || true
  systemctl disable openresty 2>/dev/null || true
}
BASH;
    }

    /**
     * Run before any dpkg configure during edge-proxy install so half-installed
     * openresty packages do not try to bind :80 (already used by Caddy/nginx).
     */
    public static function preDpkgConfigureScript(): string
    {
        return self::installShellHelpers().<<<'BASH'

write_openresty_placeholder_config
install_openresty_policy_rc_d
echo "[dply] openresty preconfigure — placeholder nginx.conf + policy-rc.d before dpkg configure."
dpkg --force-confdef --force-confold --configure -a 2>&1 || true
ensure_openresty_stopped
BASH;
    }

    public static function installScript(): string
    {
        return self::installShellHelpers().<<<'BASH'

set -euo pipefail
write_openresty_placeholder_config
install_openresty_policy_rc_d
if openresty_pkg_configured && command -v openresty >/dev/null 2>&1; then
  echo "[dply] openresty already installed; skipping package install."
  ensure_openresty_stopped
else
  apt-get install -y --no-install-recommends curl ca-certificates gnupg lsb-release
  curl -fsSL https://openresty.org/package/pubkey.gpg | gpg --batch --yes --dearmor -o /usr/share/keyrings/openresty.gpg
  # shellcheck source=/dev/null
  . /etc/os-release 2>/dev/null || true
  CODENAME=$(lsb_release -sc 2>/dev/null || echo bookworm)
  ARCH=$(dpkg --print-architecture 2>/dev/null || echo amd64)
  case "${ID:-}" in
    ubuntu)
      if [ "$ARCH" = "arm64" ] || [ "$ARCH" = "aarch64" ]; then
        REPO="https://openresty.org/package/arm64/ubuntu"
      else
        REPO="https://openresty.org/package/ubuntu"
      fi
      echo "deb [arch=${ARCH} signed-by=/usr/share/keyrings/openresty.gpg] ${REPO} ${CODENAME} main" > /etc/apt/sources.list.d/openresty.list
      ;;
    debian)
      echo "deb [signed-by=/usr/share/keyrings/openresty.gpg] https://openresty.org/package/debian ${CODENAME} openresty" > /etc/apt/sources.list.d/openresty.list
      ;;
    *)
      echo "[dply] unsupported Linux ID for OpenResty packages: ${ID:-unknown}" >&2
      exit 127
      ;;
  esac
  echo "[dply] policy-rc.d shim active — openresty will not auto-start on :80 during package install."
  apt-get update -y
  write_openresty_placeholder_config
  if ! openresty_pkg_configured; then
    apt-get install -y --no-install-recommends openresty
  fi
  write_openresty_placeholder_config
  if ! openresty_pkg_configured; then
    dpkg --force-confdef --force-confold --configure openresty
  fi
  if ! openresty_pkg_configured; then
    remove_openresty_policy_rc_d
    echo "[dply] openresty package failed to configure" >&2
    exit 127
  fi
  ensure_openresty_stopped
fi
remove_openresty_policy_rc_d
mkdir -p /var/log/openresty /usr/local/openresty/nginx/logs
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
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        return [
            'values' => self::operatorSettingsFromServer($server),
            'unreadable' => false,
        ];
    }

    /**
     * @param  array<string, mixed> $values
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
