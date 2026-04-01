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

        $lastError = null;
        $out = null;

        foreach ($this->sshLoginCandidates($server) as $loginUser) {
            try {
                $ssh = $this->makeConnection($server, $loginUser);
                $out = $ssh->exec('bash -lc '.escapeshellarg($this->readCrontabCommand($server, $u, $loginUser)), $timeoutSeconds);
                $ssh->disconnect();
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($out === null) {
            throw $lastError ?? new \RuntimeException(__('SSH connection failed for all cron login candidates.'));
        }

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

    /**
     * @return list<string>
     */
    protected function sshLoginCandidates(Server $server): array
    {
        $deploy = trim((string) $server->ssh_user) ?: 'root';
        $useRoot = (bool) config('server_cron.use_root_ssh', true);
        $fallback = (bool) config('server_cron.fallback_to_deploy_user_ssh', true);

        if (! $useRoot || $deploy === 'root') {
            return [$deploy];
        }

        return $fallback ? ['root', $deploy] : ['root'];
    }

    protected function readCrontabCommand(Server $server, string $crontabUser, string $loginUser): string
    {
        $sshLogin = trim((string) $server->ssh_user) ?: 'root';

        if ($loginUser === 'root') {
            return 'crontab -u '.escapeshellarg($crontabUser).' -l 2>&1; rc=$?; printf \'\\nDPLY_CRON_EXIT:%s\\n\' "$rc"';
        }

        if ($crontabUser === $sshLogin) {
            return 'crontab -l 2>&1; rc=$?; printf \'\\nDPLY_CRON_EXIT:%s\\n\' "$rc"';
        }

        return 'sudo crontab -u '.escapeshellarg($crontabUser).' -l 2>&1; rc=$?; printf \'\\nDPLY_CRON_EXIT:%s\\n\' "$rc"';
    }

    protected function makeConnection(Server $server, string $loginUser): SshConnection
    {
        $role = $loginUser === 'root'
            ? SshConnection::ROLE_RECOVERY
            : SshConnection::ROLE_OPERATIONAL;

        return new SshConnection($server, $loginUser, $role);
    }
}
