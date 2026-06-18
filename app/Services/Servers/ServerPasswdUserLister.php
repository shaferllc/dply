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
    /** @return array<string, mixed> */
    /**
     * @return list<string>
     */
    public function listUsernames(Server $server, int $maxLines = 500, int $timeoutSeconds = 20): array
    {
        $details = $this->listPasswdDetails($server, $maxLines, $timeoutSeconds);

        return array_values(array_map(static fn (array $row): string => $row['username'], $details));
    }

    /**
     * Single-round-trip probe: /etc/passwd entries (UID >= 1000, no `nobody`) plus group memberships.
     * Returns one row per user with uid, home, shell and the list of groups (primary + supplementary).
     *
     * @return list<string>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, int|list<string>|string>>
     */
    public function listPasswdDetails(Server $server, int $maxLines = 500, int $timeoutSeconds = 20): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException(__('Server must be ready with an SSH key.'));
        }

        $maxLines = max(1, min(2000, $maxLines));

        $cmd = sprintf(
            '{ awk -F: \'$3 >= 1000 && $1 != "nobody" { print "U:"$1":"$3":"$4":"$6":"$7 }\' /etc/passwd 2>/dev/null | sort -u | head -n %d; echo "---GROUPS---"; getent group 2>/dev/null | awk -F: \'{ print "G:"$3":"$1":"$4 }\'; }',
            $maxLines,
        );

        // /etc/passwd and `getent group` are world-readable, so this runs fine as the
        // unprivileged deploy user — no need to take the recovery (root) credential.
        $out = app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($cmd, $timeoutSeconds),
            useRoot: false,
        );

        return $this->parsePasswdAndGroups($out);
    }

    /**
     * @return list<array<string, int|list<string>|string>>
     */
    private function parsePasswdAndGroups(string $out): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($out)) ?: [];

        /** @var array $users */
        $users = [];
        /** @var array $gidToName */
        $gidToName = [];
        /** @var array<string, list<string>> $groupMembers */
        $groupMembers = [];

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || $line === '---GROUPS---') {
                continue;
            }
            if (str_starts_with($line, 'U:')) {
                $parts = explode(':', substr($line, 2), 5);
                if (count($parts) !== 5) {
                    continue;
                }
                [$name, $uid, $gid, $home, $shell] = $parts;
                if (! preg_match('/^[a-zA-Z0-9._-]+$/', $name) || ! ctype_digit($uid) || ! ctype_digit($gid)) {
                    continue;
                }
                $users[] = [
                    'username' => $name,
                    'uid' => (int) $uid,
                    'gid' => (int) $gid,
                    'home' => $home,
                    'shell' => $shell,
                ];
            } elseif (str_starts_with($line, 'G:')) {
                $parts = explode(':', substr($line, 2), 3);
                if (count($parts) < 2) {
                    continue;
                }
                $gid = $parts[0];
                $gname = $parts[1];
                $members = $parts[2] ?? '';
                if (! ctype_digit($gid) || ! preg_match('/^[a-zA-Z0-9._-]+$/', $gname)) {
                    continue;
                }
                $gidToName[(int) $gid] = $gname;
                $groupMembers[$gname] = array_values(array_filter(
                    array_map('trim', explode(',', $members)),
                    static fn (string $m): bool => $m !== '' && (bool) preg_match('/^[a-zA-Z0-9._-]+$/', $m),
                ));
            }
        }

        $rows = [];
        foreach ($users as $u) {
            $groups = [];
            $primary = $gidToName[$u['gid']] ?? null;
            if ($primary !== null) {
                $groups[$primary] = true;
            }
            foreach ($groupMembers as $gname => $members) {
                if (in_array($u['username'], $members, true)) {
                    $groups[$gname] = true;
                }
            }
            ksort($groups);

            $rows[] = [
                'username' => $u['username'],
                'uid' => $u['uid'],
                'home' => $u['home'],
                'shell' => $u['shell'],
                'groups' => array_keys($groups),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['username'], $b['username']));

        return $rows;
    }
}
