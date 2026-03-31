<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

/**
 * Reads a user’s crontab over SSH (same login as Dply: server SSH user, sudo for other users).
 */
class ServerCrontabReader
{
    /**
     * @return array{body: string, exit_code: int|null}
     */
    public function readForUser(Server $server, string $crontabUser, int $timeoutSeconds = 30): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException(__('Server must be ready with an SSH key.'));
        }

        $u = trim($crontabUser);
        if ($u === '' || ! preg_match('/^[a-zA-Z0-9._-]+$/', $u)) {
            throw new \InvalidArgumentException(__('Use a valid Linux username.'));
        }

        $sshLogin = trim((string) $server->ssh_user) ?: 'root';

        if ($u === $sshLogin) {
            $inner = 'crontab -l 2>&1; rc=$?; printf \'\\nDPLY_CRON_EXIT:%s\\n\' "$rc"';
        } else {
            $inner = 'sudo crontab -u '.escapeshellarg($u).' -l 2>&1; rc=$?; printf \'\\nDPLY_CRON_EXIT:%s\\n\' "$rc"';
        }

        $ssh = new SshConnection($server);
        $out = $ssh->exec('bash -lc '.escapeshellarg($inner), $timeoutSeconds);

        $exitCode = null;
        if (preg_match('/\nDPLY_CRON_EXIT:(\d+)\s*$/', $out, $m)) {
            $exitCode = (int) $m[1];
            $body = substr($out, 0, -strlen($m[0]));
        } else {
            $body = $out;
        }

        $body = rtrim($body, "\n");

        return [
            'body' => $body,
            'exit_code' => $exitCode,
        ];
    }
}
