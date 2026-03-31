<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Modules\TaskRunner\ProcessOutput;

/**
 * Reads ~/.ssh/authorized_keys on the remote host for a given Linux user.
 */
class ServerAuthorizedKeysRemoteReader
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function readAuthorizedKeysFile(Server $server, string $targetUser): ProcessOutput
    {
        $connectionUser = (string) $server->ssh_user;

        $readBash = $targetUser === $connectionUser
            ? 'test -f ~/.ssh/authorized_keys && cat ~/.ssh/authorized_keys || true'
            : sprintf(
                'sudo -n -u %s bash -lc %s',
                escapeshellarg($targetUser),
                escapeshellarg('test -f ~/.ssh/authorized_keys && cat ~/.ssh/authorized_keys || true')
            );

        return $this->remote->runInlineBash(
            $server,
            'Read authorized_keys ('.$targetUser.')',
            $readBash,
            30,
        );
    }

    /**
     * @return list<string>
     */
    public function normalizedKeyLines(Server $server, string $targetUser): array
    {
        $read = $this->readAuthorizedKeysFile($server, $targetUser);
        if (! $read->isSuccessful()) {
            throw new \RuntimeException('Failed to read authorized_keys for '.$targetUser.': '.$read->getBuffer());
        }

        $existing = trim($read->getBuffer());
        if ($existing === '') {
            return [];
        }

        $lines = array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $existing)));

        return array_values(array_unique($lines));
    }
}
