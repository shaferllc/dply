<?php

namespace App\Services\Servers;

use App\Models\Server;

class ServerAuthorizedKeysHealthCheck
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array{ok: bool, sshd_config: string, auth_keys_stat: string}
     */
    public function run(Server $server): array
    {
        $script = "#!/bin/bash\nset -u\n";
        $script .= 'OUT=$(sudo -n sshd -t 2>&1); EC=$?; printf "%s\nDPLY_SSHD_EXIT:%s" "$OUT" "$EC"'."\n";

        $sshd = $this->remote->runScript($server, 'sshd configuration test', $script, 25, true);

        $statBash = 'if test -f ~/.ssh/authorized_keys; then stat -c "%a %U:%G %n" ~/.ssh/authorized_keys 2>/dev/null || stat -f "%Sp %Su:%Sg %N" ~/.ssh/authorized_keys 2>/dev/null; else echo "missing"; fi';
        $stat = $this->remote->runInlineBash(
            $server,
            'stat authorized_keys (login user)',
            $statBash,
            20,
        );

        $sshdOk = str_contains($sshd->getBuffer(), 'DPLY_SSHD_EXIT:0');

        return [
            'ok' => $sshdOk && $stat->isSuccessful(),
            'sshd_config' => trim($sshd->getBuffer()),
            'auth_keys_stat' => trim($stat->getBuffer()),
        ];
    }
}
