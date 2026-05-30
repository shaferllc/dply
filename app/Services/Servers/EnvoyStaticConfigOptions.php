<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Install Envoy binary + systemd unit (shared with {@see AddEdgeProxyJob}).
 *
 * dply's {@see AddEdgeProxyJob::writeEnvoyEdgeConfig()} rewrites
 * `/etc/envoy/envoy.yaml` on every edge-proxy add — operators tuning
 * it here see their edits revert if they reinstall the edge proxy.
 */
class EnvoyStaticConfigOptions
{
    /**
     * Install Envoy binary + systemd unit (shared with {@see AddEdgeProxyJob}).
     */
    public static function installScript(): string
    {
        return <<<'BASH'
set -euo pipefail
DPLY_ARCH=$(uname -m)
case "$DPLY_ARCH" in
  x86_64|amd64) DPLY_ARCH=x86_64 ;;
  aarch64|arm64) DPLY_ARCH=aarch_64 ;;
  *) echo "[dply] unsupported arch: $DPLY_ARCH" >&2; exit 127 ;;
esac

if [ -x /usr/local/bin/envoy ] && [ -f /etc/systemd/system/envoy.service ]; then
  echo "[dply] envoy already installed; skipping."
else
  apt-get install -y --no-install-recommends curl ca-certificates
  ENVOY_VERSION="${ENVOY_VERSION:-1.32.6}"
  ENVOY_URL="https://github.com/envoyproxy/envoy/releases/download/v${ENVOY_VERSION}/envoy-${ENVOY_VERSION}-linux-${DPLY_ARCH}"
  echo "[dply] downloading envoy ${ENVOY_VERSION} (linux/${DPLY_ARCH})…"
  curl -fSL "$ENVOY_URL" -o /tmp/envoy.bin
  install -m 0755 /tmp/envoy.bin /usr/local/bin/envoy
  rm -f /tmp/envoy.bin
  echo "[dply] envoy installed at /usr/local/bin/envoy"
fi
mkdir -p /etc/envoy
cat > /etc/systemd/system/envoy.service <<'UNIT'
[Unit]
Description=Envoy Proxy
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/envoy -c /etc/envoy/envoy.yaml
Restart=on-failure
RestartSec=10s
StartLimitIntervalSec=120
StartLimitBurst=5
TimeoutStartSec=120
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
[ -x /usr/local/bin/envoy ] || { echo "[dply] envoy binary missing at /usr/local/bin/envoy" >&2; exit 127; }
[ -f /etc/systemd/system/envoy.service ] || { echo "[dply] envoy.service file missing" >&2; exit 127; }
systemctl cat envoy.service >/dev/null 2>&1 || { echo "[dply] envoy.service not picked up by systemd" >&2; exit 127; }
BASH;
    }
}
