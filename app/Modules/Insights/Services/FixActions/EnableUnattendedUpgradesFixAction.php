<?php

namespace App\Modules\Insights\Services\FixActions;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\Contracts\InsightFixActionInterface;
use App\Modules\Insights\Services\FixResult;
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

    /**
     * @param  array<string, mixed> $params
     */
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

    /**
     * @param  array<string, mixed> $params
     */
    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        $script = <<<'BASH'
set -eu

if ! command -v apt-get >/dev/null 2>&1; then
  echo "DPLY_ERR: apt-get not found — not a Debian/Ubuntu host"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

# Wait up to ~60s for any apt/dpkg run in progress (cloud-init, unattended-upgrades
# itself, a concurrent apt-daily) to release the locks. Starting the timer while
# the apt frontend lock is held causes apt-daily-upgrade.service to fail on its
# first Persistent= catch-up run, which surfaces as "Job failed" against the timer.
wait_for_apt() {
  for _ in $(seq 1 30); do
    if ! fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
      && ! fuser /var/lib/apt/lists/lock >/dev/null 2>&1 \
      && ! fuser /var/lib/dpkg/lock >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  return 1
}

echo "DPLY_STEP: waiting for apt locks"
wait_for_apt || echo "DPLY_WARN: apt locks still held after 60s — proceeding anyway"

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

# Unmask the timers AND the services they trigger. On minimal/cloud images these
# are frequently masked (symlinked to /dev/null); a masked .service makes systemd
# refuse to start the timer with "unit apt-daily-upgrade.service to trigger not
# loaded" — installing unattended-upgrades does NOT restore them because the
# service units ship in the `apt` package, not unattended-upgrades.
echo "DPLY_STEP: unmasking apt-daily units"
systemctl unmask apt-daily-upgrade.timer apt-daily.timer \
  apt-daily-upgrade.service apt-daily.service 2>/dev/null || true

# Pick up any unit file changes the package install / unmask touched before we
# try to enable/start the timers.
systemctl daemon-reload 2>/dev/null || true

# 3. Enable + start the systemd timers. Split enable from start so we can
#    report which step actually failed, and surface the real systemd error
#    (status + journal tail) when start fails instead of just "Job failed".
enable_and_start_timer() {
  unit=$1
  echo "DPLY_STEP: enabling ${unit}"
  if ! enable_out=$(systemctl enable "${unit}" 2>&1); then
    echo "DPLY_ERR: systemctl enable ${unit} failed"
    printf '%s\n' "${enable_out}" | tail -n 20
    return 1
  fi
  # Wait once more — the install may have queued an apt-daily run that holds
  # the dpkg lock, which would make the timer's Persistent= catch-up immediately
  # invoke the service and fail.
  wait_for_apt || true
  echo "DPLY_STEP: starting ${unit}"
  if ! start_out=$(systemctl start "${unit}" 2>&1); then
    echo "DPLY_ERR: systemctl start ${unit} failed"
    printf '%s\n' "${start_out}" | tail -n 20
    # The triggered .service being masked/missing is a distinct failure from a
    # lock-contention catch-up failure — call it out so the operator knows the
    # unit files themselves are the problem, not a transient apt run.
    case "${start_out}" in
      *"to trigger not loaded"*|*"Refusing to start"*)
        service_unit=${unit%.timer}.service
        echo "DPLY_HINT: ${service_unit} is masked or missing — run 'systemctl unmask ${service_unit}', or 'apt-get install --reinstall apt' if the unit file is absent."
        ;;
    esac
    echo "--- systemctl status ${unit} ---"
    systemctl status "${unit}" --no-pager --lines=20 2>&1 | tail -n 30 || true
    service_unit=${unit%.timer}.service
    echo "--- journalctl -xeu ${service_unit} ---"
    journalctl -xeu "${service_unit}" --no-pager --lines=30 2>&1 | tail -n 40 || true
    return 1
  fi
  return 0
}

enable_and_start_timer apt-daily-upgrade.timer || exit 1
enable_and_start_timer apt-daily.timer         || exit 1

# 4. Final state check — refuse to mark success unless the timer is actually
#    active. is-active prints the state to stdout AND exits non-zero for any
#    non-active state, so capture stdout first and use ${var:-unknown} for the
#    truly-empty case (unit missing, systemctl broken) — never compose with
#    `|| echo unknown` inside the same command substitution.
state=$(systemctl is-active apt-daily-upgrade.timer 2>/dev/null || true)
state=${state:-unknown}
if [ "${state}" != "active" ]; then
  echo "DPLY_ERR: apt-daily-upgrade.timer is ${state} after enable+start"
  systemctl status apt-daily-upgrade.timer --no-pager --lines=20 2>&1 | tail -n 30 || true
  exit 1
fi
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
