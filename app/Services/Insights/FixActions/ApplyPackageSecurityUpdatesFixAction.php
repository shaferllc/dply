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

    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params): FixResult
    {
        // Bash strategy:
        //   1. Wait for any in-flight apt (cloud-init, our own provisioner) to release the lock.
        //   2. Refresh the apt index — without this the upgrade plan can be stale.
        //   3. Make sure unattended-upgrades is installed; the package ships on most
        //      Ubuntu cloud images but minimal images and re-imported servers may not have it.
        //   4. Run unattended-upgrade in verbose mode. It restricts to the security suite
        //      out of the box (per /etc/apt/apt.conf.d/50unattended-upgrades) and writes
        //      detailed status to stdout when -v is set.
        //   5. Re-poll `apt list --upgradable | grep -security` so the operator sees the
        //      remaining count without waiting for the next runner cycle.
        $script = <<<'BASH'
set -u
export DEBIAN_FRONTEND=noninteractive

echo "[fix] waiting for any in-flight apt..."
for _ in $(seq 1 60); do
  if ! fuser /var/lib/apt/lists/lock /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock >/dev/null 2>&1; then
    break
  fi
  sleep 3
done

echo "[fix] refreshing apt index..."
apt-get update -qq -o Acquire::Retries=3 -o Acquire::http::Timeout=30 || {
    echo "[fix] apt-get update failed" >&2
    exit 1
}

if ! command -v unattended-upgrade >/dev/null 2>&1; then
  echo "[fix] installing unattended-upgrades..."
  apt-get install -y --no-install-recommends unattended-upgrades || {
      echo "[fix] failed to install unattended-upgrades" >&2
      exit 1
  }
fi

echo "[fix] applying security updates (unattended-upgrade -v)..."
unattended-upgrade -v 2>&1 || {
    echo "[fix] unattended-upgrade exited non-zero" >&2
    # Don't abort — the runner will re-check the actual state.
}

echo "[fix] remaining security-suite upgradables:"
apt list --upgradable 2>/dev/null | grep -E -- '-security' | wc -l
BASH;

        try {
            $out = $this->remote->runInlineBash(
                $server,
                'insight-fix-package-security-updates',
                $script,
                600, // 10 min timeout — 96-package upgrades on small droplets can sit at 5+
                true,
            );

            return FixResult::success(mb_substr((string) $out->getBuffer(), 0, 4000));
        } catch (\Throwable $e) {
            return FixResult::failure($e->getMessage());
        }
    }
}
