<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

/**
 * Mirror of {@see SiteEnvPusher} for the read path: opens an SSH session
 * to the site's host and returns the raw contents of the live `.env` file
 * sitting in {@see Site::effectiveEnvFilePath()}.
 *
 * Always runs the `cat` via sudo so the read works regardless of who owns
 * the file — the pusher writes it as root:<site-group> 640, and the SSH
 * login user is often the deploy user, not the site's runtime user.
 *
 * Returns an empty string when the file doesn't exist (the `if [ ! -e ]; then exit 0; fi`
 * guard). Callers decide whether "no file on disk" means "wipe the cache"
 * or "leave it alone" — this service does not write to the DB; it only fetches.
 */
class SiteEnvReader
{
    public function __construct() {}

    public function read(Site $site): string
    {
        $server = $site->server;
        if (! $server->hostCapabilities()->supportsEnvPushToHost()) {
            throw new \RuntimeException('This host runtime does not expose a server .env file.');
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $path = $site->effectiveEnvFilePath();
        $ssh = new SshConnection($server);

        // Always sudo — the .env file is written by the pusher as
        // root:<site-group> 640 (see SiteEnvPusher), so the SSH login user
        // (typically the deploy user, not the site's runtime user) can't
        // necessarily read it directly. Running cat as root sidesteps the
        // ownership question entirely. Mirrors the always-sudo decision in
        // the pusher and keeps push/read symmetric.
        //
        // Two exit codes are meaningful:
        //   - 0 + empty stdout  : file does not exist (first-time load,
        //                         not an error — return empty string)
        //   - 0 + content       : success
        //   - non-zero          : unexpected failure (sudo denied, file
        //                         exists but unreadable somehow, etc.) —
        //                         we throw with the captured stderr so the
        //                         banner shows the real reason.
        $escaped = escapeshellarg($path);
        $inner = "if [ ! -e $escaped ]; then exit 0; fi; cat $escaped";
        $wrapped = 'sudo -n bash -lc '.escapeshellarg($inner);

        $output = (string) $ssh->exec($wrapped, 60);
        $exit = $ssh->lastExecExitCode();

        if ($exit !== null && $exit !== 0) {
            $detail = trim($output);
            if ($detail === '') {
                $detail = '(no output captured)';
            }
            throw new \RuntimeException(sprintf('Failed to read .env from %s (exit %d): %s', $path, $exit, $detail));
        }

        return $output;
    }
}
