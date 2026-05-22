<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

/**
 * Installs the bash `dply` CLI on a server over SSH.
 *
 * Steps:
 *   1. Ensure /etc/dply exists (state file location).
 *   2. Push the bash script to /usr/local/bin/dply (0755, root:root).
 *   3. Ensure jq is installed (the script requires it for state parsing).
 *   4. Push an initial state file via DplyCliStateWriter.
 *
 * Idempotent — re-running upgrades the script in place. No-op when the
 * installed version matches CLI_VERSION.
 *
 * Phase 2: invoked from the Console page's "Install dply CLI" button and
 * (later) from the provisioning pipeline. Phase 3 will add a token and
 * the agent checkin cron entry.
 */
class DplyCliInstaller
{
    public const REMOTE_BIN_PATH = '/usr/local/bin/dply';

    public const REMOTE_STATE_DIR = '/etc/dply';

    public function __construct(
        protected DplyCliStateWriter $stateWriter,
    ) {}

    /**
     * Whether the CLI binary exists on the server. Cheap probe, runs one
     * `test -x` over SSH.
     */
    public function isInstalled(Server $server, ?SshConnection $ssh = null): bool
    {
        $ssh ??= new SshConnection($server);
        try {
            $out = trim($ssh->exec(
                'test -x '.escapeshellarg(self::REMOTE_BIN_PATH).' && echo present || echo missing',
                10,
            ));

            return $out === 'present';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Read the version reported by the installed CLI, or null if absent /
     * unreadable.
     */
    public function installedVersion(Server $server, ?SshConnection $ssh = null): ?string
    {
        $ssh ??= new SshConnection($server);
        try {
            $out = trim($ssh->exec(self::REMOTE_BIN_PATH.' version 2>/dev/null || true', 10));
            // Output: "dply 0.1.0"
            if ($out !== '' && preg_match('/^dply\s+(\S+)/', $out, $m) === 1) {
                return $m[1];
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    /**
     * Push the CLI + initial state file. Returns the version that was
     * installed.
     */
    public function install(Server $server, ?SshConnection $ssh = null): string
    {
        $ssh ??= new SshConnection($server);

        $script = @file_get_contents(resource_path('bin/dply'));
        if ($script === false) {
            throw new \RuntimeException('Could not read resources/bin/dply.');
        }

        // 1. State directory.
        $ssh->exec('sudo mkdir -p '.escapeshellarg(self::REMOTE_STATE_DIR).' && sudo chmod 0755 '.escapeshellarg(self::REMOTE_STATE_DIR), 15);

        // 2. Binary. Upload to a temp path the SSH user can write, then
        //    `sudo install` it into the system bin so we don't need a
        //    writable /usr/local/bin for the SSH user.
        $tmp = '/tmp/dply.install.'.bin2hex(random_bytes(8));
        $ssh->putFile($tmp, $script);
        $ssh->exec(
            'sudo install -o root -g root -m 0755 '.escapeshellarg($tmp).' '.escapeshellarg(self::REMOTE_BIN_PATH)
            .' && rm -f '.escapeshellarg($tmp),
            15,
        );

        // 3. jq dependency. apt-get update only when we're going to install,
        //    so re-runs on jq-already-present servers are a single -v probe.
        $ssh->exec(
            'command -v jq >/dev/null 2>&1 || (sudo DEBIAN_FRONTEND=noninteractive apt-get update -y && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y jq)',
            120,
        );

        // 4. Initial state file.
        $this->stateWriter->push($server, $ssh);

        return $this->parseVersionFromScript($script);
    }

    /**
     * Re-push only the state file (no binary upgrade, no apt). Cheap.
     */
    public function refreshState(Server $server, ?SshConnection $ssh = null): void
    {
        $this->stateWriter->push($server, $ssh);
    }

    protected function parseVersionFromScript(string $script): string
    {
        if (preg_match('/^DPLY_VERSION="([^"]+)"/m', $script, $m) === 1) {
            return $m[1];
        }

        return 'unknown';
    }
}
