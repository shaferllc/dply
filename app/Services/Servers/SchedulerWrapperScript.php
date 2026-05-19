<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Mirrors {@see ServerMetricsGuestScript} for the dply-scheduler-tick wrapper.
 *
 * The wrapper is the load-bearing piece of the scheduler-monitoring pipeline
 * (per the schedule-page-v1 plan): every framework-scheduler tick invokes it,
 * it writes heartbeats the metrics agent ships to the dply control plane.
 *
 * Install location is /usr/local/bin/dply-scheduler-tick (system-wide, sudo-
 * installed) so cron entries under any user can invoke it. The companion
 * directories under /var/lib/dply/ are owned by the deploy user so the
 * default cron-runs-as-deploy-user path works without further chowns.
 */
class SchedulerWrapperScript
{
    public const REMOTE_PATH = '/usr/local/bin/dply-scheduler-tick';

    public const HEARTBEAT_DIR = '/var/lib/dply/scheduler-heartbeats';

    public const LOCK_DIR = '/var/lib/dply/scheduler-locks';

    public const STATE_DIR = '/var/lib/dply/scheduler-state';

    public function localPath(): string
    {
        return resource_path('server-scripts/dply-scheduler-tick');
    }

    public function bundledSha256(): string
    {
        $local = $this->localPath();
        if (! is_readable($local)) {
            throw new \RuntimeException('Missing scheduler wrapper at '.$local);
        }

        $hash = hash_file('sha256', $local);
        if ($hash === false) {
            throw new \RuntimeException('Could not hash scheduler wrapper at '.$local);
        }

        return $hash;
    }

    /**
     * Bash fragment that (1) creates the data directories under /var/lib/dply/
     * owned by $deployUser, (2) base64-decodes the wrapper into the system
     * binary path with mode 0755, (3) verifies SHA-256 against the bundled
     * expectation. Mirrors {@see ServerMetricsGuestScript::guestScriptDeployBashFragment()}
     * shape so the same deploy patterns + failure handling apply.
     *
     * Idempotent — re-running on an up-to-date install is a no-op aside from
     * the SHA verification. Re-running with a new wrapper version replaces the
     * binary atomically (mv from tempfile).
     */
    public function installBashFragment(string $deployUser): string
    {
        $local = $this->localPath();
        if (! is_readable($local)) {
            throw new \RuntimeException('Missing scheduler wrapper at '.$local);
        }

        $payload = (string) file_get_contents($local);
        $b64 = base64_encode($payload);
        $b64Lines = rtrim(chunk_split($b64, 76, "\n"));
        $expectedSha = $this->bundledSha256();

        // escapeshellarg on the username because it lands inside chown / chgrp
        // / mkdir invocations; we trust the upstream validator already
        // enforced [a-z_][a-z0-9_-]* but defense in depth costs nothing.
        $userArg = escapeshellarg($deployUser);

        $heartbeatDir = self::HEARTBEAT_DIR;
        $lockDir = self::LOCK_DIR;
        $stateDir = self::STATE_DIR;
        $remotePath = self::REMOTE_PATH;

        $script = <<<BASH
# ---- dply scheduler wrapper install ---------------------------------------
# Directories first — wrapper falls through to silent-skip on missing dirs,
# so installing them up-front is what makes the heartbeat pipe work.
sudo -n install -d -m 0755 -o {$userArg} -g {$userArg} {$heartbeatDir} {$lockDir} {$stateDir}

# Decode the wrapper to a tempfile then move atomically into place. mv across
# the same filesystem is atomic; partial writes never become the live binary.
SCHED_TMP_B64="\$(mktemp)"
cat <<'DPLY_SCHED_B64' > "\$SCHED_TMP_B64"
{$b64Lines}
DPLY_SCHED_B64
SCHED_TMP_BIN="\$(mktemp)"
base64 -d "\$SCHED_TMP_B64" > "\$SCHED_TMP_BIN" || { rm -f "\$SCHED_TMP_B64" "\$SCHED_TMP_BIN"; echo "wrapper b64 decode failed"; exit 1; }
rm -f "\$SCHED_TMP_B64"
test -s "\$SCHED_TMP_BIN" || { rm -f "\$SCHED_TMP_BIN"; echo "wrapper tempfile empty"; exit 1; }

ACTUAL_SHA="\$(sha256sum "\$SCHED_TMP_BIN" | awk '{print \$1}')"
test "\$ACTUAL_SHA" = "{$expectedSha}" || { rm -f "\$SCHED_TMP_BIN"; echo "wrapper sha mismatch: \$ACTUAL_SHA"; exit 1; }

sudo -n install -m 0755 -o root -g root "\$SCHED_TMP_BIN" {$remotePath} || { rm -f "\$SCHED_TMP_BIN"; echo "wrapper install to {$remotePath} failed"; exit 1; }
rm -f "\$SCHED_TMP_BIN"
echo "Installed Dply scheduler wrapper at {$remotePath}"
# ---- end scheduler wrapper install ----------------------------------------
BASH;

        return str_replace("\r\n", "\n", $script);
    }
}
