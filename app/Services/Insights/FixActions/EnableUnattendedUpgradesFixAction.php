<?php

namespace App\Services\Insights\FixActions;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\FixResult;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Install and enable unattended-upgrades on Debian/Ubuntu. Three concerns:
 *   1. Package present (`apt install -y unattended-upgrades`).
 *   2. /etc/apt/apt.conf.d/20auto-upgrades has both periodic + unattended set to 1.
 *   3. apt-daily-upgrade.timer (systemd) enabled and started.
 *
 * Not revertable: uninstalling a system maintenance tool is a meaningful
 * decision the operator should make manually, not something the dashboard
 * should be one-clicking. The fix is also idempotent — running it on an
 * already-correct server is a no-op.
 */
class EnableUnattendedUpgradesFixAction implements InsightFixActionInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function preflight(Server $server, ?Site $site, InsightFinding $finding, array $params): ?string
    {
        if (! $server->isReady()) {
            return __('Server is not ready.');
        }
        if (blank($server->ip_address)) {
            return __('Server has no IP address recorded.');
        }

        return null;
    }

    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        $script = <<<'BASH'
set -eu

if ! command -v apt-get >/dev/null 2>&1; then
  echo "DPLY_ERR: apt-get not found — not a Debian/Ubuntu host"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

# 1. Install (idempotent — apt is a no-op when already installed and current).
echo "DPLY_STEP: installing unattended-upgrades"
apt-get update -qq 2>&1 | tail -n 5 || true
apt-get install -y -qq unattended-upgrades 2>&1 | tail -n 20

# 2. Write the periodic config. Use a heredoc so the file is fully-formed
#    after the write — we don't append, we overwrite.
echo "DPLY_STEP: writing /etc/apt/apt.conf.d/20auto-upgrades"
cat >/etc/apt/apt.conf.d/20auto-upgrades <<'CFG'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
CFG

# 3. Enable + start the systemd timer.
echo "DPLY_STEP: enabling apt-daily-upgrade.timer"
systemctl enable --now apt-daily-upgrade.timer 2>&1 | tail -n 5 || true
systemctl enable --now apt-daily.timer 2>&1 | tail -n 5 || true

# 4. Final state echo so the runner re-check sees the same key=value shape.
state=$(systemctl is-active apt-daily-upgrade.timer 2>/dev/null || echo unknown)
echo "DPLY_OK: timer_state=${state}"
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-fix-enable-uu', $script, 180, true);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            return FixResult::failure(__('Failed to enable unattended-upgrades: :err', ['err' => $e->getMessage()]));
        }

        if (str_contains($buffer, 'DPLY_ERR:')) {
            return FixResult::failure(mb_substr(trim($buffer), 0, 2000));
        }
        if (! str_contains($buffer, 'DPLY_OK:')) {
            return FixResult::failure(__('Apply finished without the expected success marker.')."\n".mb_substr(trim($buffer), 0, 1500));
        }

        return FixResult::success(mb_substr(trim($buffer), 0, 2000));
    }
}
