<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

class ServerFirewallProvisioner
{
    public function apply(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $ssh = new SshConnection($server);
        $log = "Applying UFW rules (ensure SSH port 22 is allowed before enabling UFW).\n";

        foreach ($server->firewallRules()->orderBy('sort_order')->get() as $rule) {
            if ($rule->action !== 'allow') {
                continue;
            }
            $proto = in_array($rule->protocol, ['tcp', 'udp'], true) ? $rule->protocol : 'tcp';
            $log .= $ssh->exec(sprintf(
                'ufw allow %d/%s 2>&1',
                (int) $rule->port,
                $proto
            ), 60);
        }

        return $log;
    }
}
