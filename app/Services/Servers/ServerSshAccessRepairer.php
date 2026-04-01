<?php

namespace App\Services\Servers;

use App\Models\Server;

class ServerSshAccessRepairer
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function repairOperationalAccess(Server $server): string
    {
        $loginUser = trim((string) $server->ssh_user) ?: 'root';
        $operationalPublicKey = trim((string) $server->openSshPublicKeyFromOperationalPrivate());

        if ($loginUser === 'root') {
            throw new \RuntimeException('SSH repair is only needed when the operational login user is not root.');
        }

        if ($operationalPublicKey === '') {
            throw new \RuntimeException('This server does not have an operational SSH key to reinstall.');
        }

        $home = '/home/'.$loginUser;
        $encodedKey = base64_encode($operationalPublicKey."\n");

        $script = <<<'BASH'
#!/bin/bash
set -euo pipefail
login_user=__LOGIN_USER__
home_dir=__HOME_DIR__
key_body=$(printf '%s' __KEY_BODY__ | base64 -d)

id -u "$login_user" >/dev/null 2>&1
install -d -m 700 -o "$login_user" -g "$login_user" "$home_dir/.ssh"
printf '%s' "$key_body" > "$home_dir/.ssh/authorized_keys"
chown "$login_user:$login_user" "$home_dir/.ssh/authorized_keys"
chmod 600 "$home_dir/.ssh/authorized_keys"
BASH;

        $script = str_replace(
            ['__LOGIN_USER__', '__HOME_DIR__', '__KEY_BODY__'],
            [escapeshellarg($loginUser), escapeshellarg($home), escapeshellarg($encodedKey)],
            $script
        );

        return $this->remote->runScript($server, 'Repair SSH access', $script, 60, true)->getBuffer();
    }
}
