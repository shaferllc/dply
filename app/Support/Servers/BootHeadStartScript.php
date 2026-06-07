<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Boot-time head-start, delivered as cloud-init user_data.
 *
 * A freshly created server spends ~30-90s booting + becoming SSH-reachable
 * before the control plane's SSH'd provision script even starts. This script
 * runs AT BOOT (via cloud-init) and gets the slow, generic, idempotent work out
 * of the way during that window — apt index refresh + the base package set — so
 * the main provision script skip-fasts it later (dpkg -s checks + the shared
 * apt-update stamp).
 *
 * It is deliberately generic (no stack-specific php/db installs) so it can never
 * diverge from the per-server provisioner, and entirely best-effort (`|| true`):
 * the SSH'd script remains authoritative. It writes a "running" marker on start
 * and a "done" marker on finish so the provision script's cloud-init pre-empt
 * can wait for it (bounded) instead of killing it mid-flight.
 *
 * Off by default — see config('server_provision.boot_head_start').
 */
final class BootHeadStartScript
{
    public const RUNNING_MARKER = '/var/lib/dply/headstart.running';

    public const DONE_MARKER = '/var/lib/dply/headstart.done';

    /** Shared with ServerProvisionCommandBuilder's dply_apt_update sentinel. */
    public const APT_STAMP = '/var/lib/dply/apt-updated.stamp';

    public static function enabled(): bool
    {
        return (bool) config('server_provision.boot_head_start', false);
    }

    /**
     * The cloud-init user_data script. Starts with a shebang so DO/Hetzner run
     * it as a boot script.
     */
    public static function cloudInitUserData(): string
    {
        $running = self::RUNNING_MARKER;
        $done = self::DONE_MARKER;
        $stamp = self::APT_STAMP;

        return <<<BASH
#!/bin/bash
# dply boot head-start — best-effort apt warmup overlapping the IP/SSH wait.
export DEBIAN_FRONTEND=noninteractive
mkdir -p /var/lib/dply
: > {$running}
echo "[dply-headstart] \$(date -Is 2>/dev/null) starting"

# Keep cloud-init's own auto-upgrade timers from fighting us for the apt lock.
systemctl mask --now apt-daily.timer apt-daily-upgrade.timer unattended-upgrades.service >/dev/null 2>&1 || true

# Wait for any in-flight apt (cloud-init's first-boot run) to release the lock.
for _ in \$(seq 1 80); do
  if ! fuser /var/lib/apt/lists/lock /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock >/dev/null 2>&1; then
    break
  fi
  sleep 3
done

if apt-get update -y >/dev/null 2>&1 || apt-get update -y >/dev/null 2>&1; then
  _dply_hs_updated=1
else
  _dply_hs_updated=0
fi
apt-get install -y --no-install-recommends \
  ca-certificates curl git gnupg lsb-release locales software-properties-common ufw unattended-upgrades \
  >/dev/null 2>&1 || true

# Only let the main provision script skip its base update if OUR update actually
# succeeded — otherwise leave the stamp unset so the provisioner re-updates and
# its retry resilience isn't defeated by a failed boot-time refresh.
if [ "\$_dply_hs_updated" = "1" ]; then
  touch {$stamp} 2>/dev/null || true
fi

echo "[dply-headstart] \$(date -Is 2>/dev/null) done"
: > {$done}
BASH;
    }
}
