<?php

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Lists usernames from /etc/passwd on the remote host (for UI comboboxes).
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
                'cut -d: -f1 /etc/passwd 2>/dev/null | sort -u | head -n '.(string) $maxLines,
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
