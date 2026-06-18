<?php

namespace App\Modules\Insights\Services\FixActions;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\Contracts\InsightFixActionInterface;
use App\Modules\Insights\Services\FixResult;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Install fail2ban with its stock defaults and enable the systemd unit.
 * Default jail (sshd) ships enabled out of the box on Debian/Ubuntu since
 * the buster/focal era, so no per-jail config is necessary for the common
 * use case. Operators who want to extend it can drop files into
 * /etc/fail2ban/jail.d/ — we don't manage those.
 *
 * Not revertable: removing an active brute-force defence is a deliberate
 * operator decision (with their own change-control), not a dashboard click.
 */
class InstallFail2banFixAction implements InsightFixActionInterface
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

# Pick the package manager so we don't assume Debian on RHEL hosts.
if command -v apt-get >/dev/null 2>&1; then
  PM=apt
elif command -v dnf >/dev/null 2>&1; then
  PM=dnf
elif command -v yum >/dev/null 2>&1; then
  PM=yum
else
  echo "DPLY_ERR: no supported package manager (apt/dnf/yum) found"
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

case "$PM" in
  apt)
    echo "DPLY_STEP: apt-get update"
    apt-get update -qq 2>&1 | tail -n 5 || true
    echo "DPLY_STEP: installing fail2ban"
    apt-get install -y -qq fail2ban 2>&1 | tail -n 20
    ;;
  dnf|yum)
    # epel-release first on RHEL/CentOS — fail2ban isn't in the base repos.
    echo "DPLY_STEP: ensuring epel"
    $PM install -y epel-release 2>&1 | tail -n 5 || true
    echo "DPLY_STEP: installing fail2ban"
    $PM install -y fail2ban 2>&1 | tail -n 20
    ;;
esac

echo "DPLY_STEP: enabling fail2ban service"
systemctl enable --now fail2ban 2>&1 | tail -n 5 || true

state=$(systemctl is-active fail2ban 2>/dev/null || echo unknown)
echo "DPLY_OK: state=${state}"
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-fix-install-fail2ban', $script, 240, true);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            return FixResult::failure(__('Failed to install fail2ban: :err', ['err' => $e->getMessage()]));
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
