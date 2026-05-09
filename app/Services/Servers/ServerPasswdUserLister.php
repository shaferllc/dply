<?php

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Lists "regular" usernames from /etc/passwd on the remote host (UID >= 1000,
 * excluding the `nobody` overflow account). Filters out distro-shipped system
 * accounts like _apt, bin, daemon, mail, sshd, systemd-* etc. that no UI
 * combobox in this app has a legitimate use for — assigning them as a site's
 * file owner, an SSH-key target, or a cron user is never what the operator
 * means. The deploy user (typically `dply`, UID 1000) is included.
 */
class ServerPasswdUserLister
{
    /**
     * @return list<string>
     */
    public function listUsernames(Server $server, int $maxLines = 500, int $timeoutSeconds = 20): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException(__('Server must be ready with an SSH key.'));
        }

        $maxLines = max(1, min(2000, $maxLines));

        $out = app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec(
                'awk -F: \'$3 >= 1000 && $1 != "nobody" { print $1 }\' /etc/passwd 2>/dev/null | sort -u | head -n '.(string) $maxLines,
                $timeoutSeconds
            )
        );

        $lines = preg_split('/\r\n|\n|\r/', trim($out)) ?: [];
        $seen = [];

        foreach ($lines as $line) {
            $u = trim($line);
            if ($u === '' || ! preg_match('/^[a-zA-Z0-9._-]+$/', $u)) {
                continue;
            }
            $seen[$u] = true;
        }

        $names = array_keys($seen);
        sort($names);

        return $names;
    }
}
