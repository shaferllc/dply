<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

class ServerSshConnectionRunner
{
    /**
     * @return list<string>
     */
    public function loginCandidates(Server $server, bool $useRoot = true, bool $fallbackToDeploy = true): array
    {
        $deploy = trim((string) $server->ssh_user) ?: 'root';

        if (! $useRoot || $deploy === 'root') {
            return [$deploy];
        }

        return $fallbackToDeploy ? ['root', $deploy] : ['root'];
    }

    /**
     * @template T
     *
     * @param  callable(SshConnection, string):T  $callback
     * @return T
     */
    public function run(Server $server, callable $callback, bool $useRoot = true, bool $fallbackToDeploy = true): mixed
    {
        $lastError = null;

        foreach ($this->loginCandidates($server, $useRoot, $fallbackToDeploy) as $loginUser) {
            $credentialRole = $loginUser === 'root'
                ? SshConnection::ROLE_RECOVERY
                : SshConnection::ROLE_OPERATIONAL;
            $ssh = $this->makeConnection($server, $loginUser, $credentialRole);

            try {
                return $callback($ssh, $loginUser);
            } catch (\Throwable $e) {
                $lastError = $e;
            } finally {
                $ssh->disconnect();
            }
        }

        throw $lastError ?? new \RuntimeException('SSH connection failed for all login candidates.');
    }

    protected function makeConnection(Server $server, string $loginUser, string $credentialRole): SshConnection
    {
        return new SshConnection($server, $loginUser, $credentialRole);
    }
}
