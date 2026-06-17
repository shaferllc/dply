<?php

namespace App\Services\Insights\FixActions;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\FixResult;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Apply outstanding apt security updates via the unattended-upgrade tool —
 * which already knows how to restrict to the security suite, hold back
 * phased / interactive packages, and write structured logs to
 * /var/log/unattended-upgrades/. The post-action recheck (problem-kind
 * lifecycle) will re-run PackageSecurityUpdatesInsightRunner and clear
 * the finding when `apt list --upgradable` no longer shows -security
 * sources.
 *
 * Runs with DEBIAN_FRONTEND=noninteractive and -force-conf{def,old} so a
 * conffile prompt never strands the upgrade. Output is bounded to 4 KB
 * in the FixResult so a 96-package run doesn't blow up the meta column.
 */
class ApplyPackageSecurityUpdatesFixAction implements InsightFixActionInterface
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
        // Bash strategy (apt-get path):
        //   1. Pause Ubuntu's apt-daily / apt-daily-upgrade timers (and stop in-flight
        //      runs) so we aren't racing the system's own unattended-upgrade. Restart
        //      them on EXIT so the box keeps its normal cadence.
        //   2. Diagnostic-print whatever's holding apt/dpkg/u-u locks so the operator
        //      log shows the actual contender instead of guessing.
        //   3. Refresh the apt index. We pass Dpkg::Lock::Timeout=180 so apt itself
        //      waits up to 3 minutes for the dpkg locks instead of failing instantly.
        //   4. Enumerate upgradable security packages via `apt list --upgradable`
        //      (parses the `-security` suite identifier). This is the same signal the
        //      runner uses, so the recheck will agree about what cleared.
        //   5. Run `apt-get install --only-upgrade` against those packages with
        //      Dpkg::Lock::Timeout=300. We deliberately do NOT use `unattended-upgrade`
        //      because it self-coordinates on /var/run/unattended-upgrades.lock, which
        //      is held by the still-running dply provisioner / cloud-init / system
        //      timer on first-boot droplets — producing the "Lock file is already
        //      taken, exiting" failure that operators kept hitting.
        //   6. Re-poll `apt list --upgradable | grep -security` and propagate failure
        //      honestly when items remain — no more "Apply ok" -> "still failing".
        $script = <<<'BASH'
set -u
export DEBIAN_FRONTEND=noninteractive

LOCK_FILES="/var/lib/apt/lists/lock /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/run/unattended-upgrades.lock"

echo "[fix] pausing apt-daily timers/services to avoid concurrent runs..."
systemctl stop apt-daily.timer apt-daily-upgrade.timer 2>/dev/null || true
systemctl stop apt-daily.service apt-daily-upgrade.service 2>/dev/null || true
trap 'systemctl start apt-daily.timer apt-daily-upgrade.timer 2>/dev/null || true' EXIT

echo "[fix] checking who currently holds apt/dpkg locks..."
any_locked=0
uu_pids=""
for f in $LOCK_FILES; do
  if [ -e "$f" ] && fuser "$f" >/dev/null 2>&1; then
    any_locked=1
    holders=$(fuser "$f" 2>&1 | sed 's/^[^:]*:[[:space:]]*//')
    echo "[fix]   $f held by: $holders"
    if [ -n "$holders" ]; then
      ps -o pid=,user=,cmd= -p $holders 2>/dev/null | sed 's/^/[fix]     /'
      # Track unattended-upgrade PIDs separately — those are doing exactly
      # the work we'd do, so we wait them out instead of fighting them.
      for pid in $holders; do
        if ps -o comm= -p "$pid" 2>/dev/null | grep -qE 'unattended-upgr|apt-get|dpkg'; then
          uu_pids="$uu_pids $pid"
        fi
      done
    fi
  fi
done
[ "$any_locked" = "0" ] && echo "[fix]   (no holders)"

# If the system is already running unattended-upgrade (or apt-get / dpkg),
# wait for it to finish before we try anything. Its install set is almost
# certainly the same as ours, so once it exits the recheck will find 0
# remaining security updates and we're done — no need to run apt-get
# ourselves. Cap the wait at 20 minutes; on a slow droplet with 77
# packages, 10-15 minutes is realistic.
if [ -n "$uu_pids" ]; then
    echo "[fix] waiting up to 20 minutes for in-flight apt/unattended-upgrade processes ($uu_pids) to finish..."
    waited=0
    while [ "$waited" -lt 1200 ]; do
        still_running=""
        for pid in $uu_pids; do
            if kill -0 "$pid" 2>/dev/null; then
                still_running="$still_running $pid"
            fi
        done
        if [ -z "$still_running" ]; then
            echo "[fix] all in-flight apt processes have exited."
            break
        fi
        sleep 5
        waited=$((waited + 5))
        # Periodic progress ping so the streaming banner doesn't look frozen.
        if [ $((waited % 30)) -eq 0 ]; then
            echo "[fix]   still waiting (${waited}s elapsed, holders:$still_running)"
        fi
    done
fi

echo "[fix] waiting up to 3 minutes for any residual dpkg/apt locks to clear..."
for _ in $(seq 1 60); do
  if ! fuser /var/lib/apt/lists/lock /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock >/dev/null 2>&1; then
    break
  fi
  sleep 3
done

echo "[fix] refreshing apt index..."
apt-get update -qq \
  -o Acquire::Retries=3 \
  -o Acquire::http::Timeout=30 \
  -o Dpkg::Lock::Timeout=180 || {
    echo "[fix] apt-get update failed" >&2
    exit 1
}

echo "[fix] enumerating security-suite upgradables..."
mapfile -t SEC_PKGS < <(apt list --upgradable 2>/dev/null | awk -F/ '/-security/ { print $1 }')
total="${#SEC_PKGS[@]}"
echo "[fix]   found $total package(s)"

if [ "$total" -eq 0 ]; then
    echo "[fix] nothing to upgrade — condition already clear."
    exit 0
fi

echo "[fix] upgrading $total security packages via apt-get..."
upgrade_rc=0
apt-get install -y \
  --only-upgrade \
  -o Dpkg::Lock::Timeout=300 \
  -o Dpkg::Options::=--force-confdef \
  -o Dpkg::Options::=--force-confold \
  "${SEC_PKGS[@]}" 2>&1 || upgrade_rc=$?

if [ "$upgrade_rc" -ne 0 ]; then
    echo "[fix] apt-get install exited with code $upgrade_rc" >&2
fi

echo "[fix] remaining security-suite upgradables:"
remaining=$(apt list --upgradable 2>/dev/null | grep -E -- '-security' | wc -l | tr -d '[:space:]')
echo "$remaining"

# Propagate failure when items remain — operator sees "Fix failed" with the
# real lock-contention or apt error instead of a misleading "Apply ok"
# followed by the recheck saying "still failing".
if [ "${remaining:-0}" -gt 0 ]; then
    if [ "$upgrade_rc" -ne 0 ]; then
        echo "[fix] upgrade did not complete and $remaining security updates remain" >&2
        echo "[fix] common causes: dpkg lock held >5 min, held packages, or apt resolver conflict" >&2
        exit "$upgrade_rc"
    fi
    # apt-get returned 0 but items remain — usually means packages are held.
    echo "[fix] $remaining security updates remain even though apt-get reported success" >&2
    echo "[fix] likely cause: packages are held (apt-mark showhold) or pinned" >&2
    exit 1
fi
BASH;

        try {
            // 30-minute timeout because the script may wait up to 20 minutes for a
            // concurrent unattended-upgrade run to finish before doing its own work,
            // plus 5-10 minutes for the actual apt-get install on a 70+ package set
            // running on a small droplet. Stream live output when a callback is
            // supplied so the banner shows progress instead of looking frozen.
            $timeout = 1800;
            if ($onOutput !== null) {
                $out = $this->remote->runInlineBashWithOutputCallback(
                    $server,
                    'insight-fix-package-security-updates',
                    $script,
                    $onOutput,
                    $timeout,
                    true,
                );
            } else {
                $out = $this->remote->runInlineBash(
                    $server,
                    'insight-fix-package-security-updates',
                    $script,
                    $timeout,
                    true,
                );
            }

            return FixResult::success(mb_substr((string) $out->getBuffer(), 0, 4000));
        } catch (\Throwable $e) {
            return FixResult::failure($e->getMessage());
        }
    }
}
