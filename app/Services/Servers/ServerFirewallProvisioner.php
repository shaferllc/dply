<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerFirewallRule;

class ServerFirewallProvisioner
{
    /**
     * UFW fragment after `ufw` (e.g. `allow 80/tcp`, `allow proto ipv6-icmp`, `deny from 10.0.0.0/8 to any port 22 proto tcp`).
     */
    public function ufwRuleFragment(ServerFirewallRule $rule): string
    {
        $p = strtolower(trim((string) $rule->protocol));

        if (in_array($p, ['icmp', 'ipv6-icmp'], true)) {
            $verb = $rule->action === 'deny' ? 'deny' : 'allow';
            $ufwProto = $p === 'ipv6-icmp' ? 'ipv6-icmp' : 'icmp';
            $source = strtolower(trim((string) $rule->source));
            if ($source === '' || $source === 'any') {
                return sprintf('%s proto %s', $verb, $ufwProto);
            }
            $src = trim((string) $rule->source);

            return sprintf('%s from %s proto %s', $verb, $src, $ufwProto);
        }

        $proto = in_array($rule->protocol, ['tcp', 'udp'], true) ? $rule->protocol : 'tcp';
        if ($rule->port === null) {
            throw new \InvalidArgumentException('TCP/UDP rules require a port.');
        }
        $port = (int) $rule->port;
        $verb = $rule->action === 'deny' ? 'deny' : 'allow';
        $source = strtolower(trim((string) $rule->source));

        if ($source === '' || $source === 'any') {
            return sprintf('%s %d/%s', $verb, $port, $proto);
        }

        $src = trim((string) $rule->source);

        return sprintf('%s from %s to any port %d proto %s', $verb, $src, $port, $proto);
    }

    public function apply(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $rules = $server->firewallRules()->where('enabled', true)->orderBy('sort_order')->get();
        if ($rules->isEmpty()) {
            return 'No enabled firewall rules to apply.';
        }

        $log = "Applying UFW rules (ensure SSH is reachable before tightening UFW).\n";

        $log .= app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($rules, $server): string {
                $output = '';

                foreach ($rules as $rule) {
                    $output .= $this->applyRuleViaSsh($ssh, $server, $rule);
                }

                return $output;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );

        return $log;
    }

    /**
     * Apply one rule to UFW (caller ensures SSH is connected and rule should be applied).
     */
    public function applyRule(Server $server, ServerFirewallRule $rule): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }
        if (! $rule->enabled) {
            return 'Skipped (rule disabled).';
        }

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $this->applyRuleViaSsh($ssh, $server, $rule),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * UFW must run as root. Non-root SSH users (e.g. deploy) use passwordless sudo, same as Supervisor/cron helpers.
     */
    private function ufwBinaryPrefix(Server $server): string
    {
        $user = trim((string) $server->ssh_user);
        if ($user === '' || $user === 'root') {
            return 'ufw';
        }

        return 'sudo -n ufw';
    }

    private function ufwExecLine(Server $server, string $arguments): string
    {
        return $this->ufwBinaryPrefix($server).' '.$arguments.' 2>&1';
    }

    private function applyRuleViaSsh($ssh, Server $server, ServerFirewallRule $rule): string
    {
        return $ssh->exec($this->ufwExecLine($server, $this->ufwRuleFragment($rule)), 60);
    }

    /**
     * Remove a single rule from UFW on the server (must match how it was added).
     */
    public function removeFromHost(Server $server, ServerFirewallRule $rule): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $fragment = $this->ufwRuleFragment($rule);

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($this->ufwExecLine($server, 'delete '.$fragment), 60),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    public function status(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => trim($ssh->exec($this->ufwExecLine($server, 'status verbose'), 60)),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * True when no enabled Dply rule clearly allows inbound SSH on the server’s configured SSH port (TCP).
     */
    public function sshAccessNotExplicitlyAllowed(Server $server): bool
    {
        $sshPort = (int) ($server->ssh_port ?: 22);
        $has = $server->firewallRules()
            ->where('enabled', true)
            ->where('action', 'allow')
            ->where('protocol', 'tcp')
            ->where('port', $sshPort)
            ->where(function ($q): void {
                $q->where('source', 'any')
                    ->orWhere('source', '0.0.0.0/0')
                    ->orWhere('source', '::/0');
            })
            ->exists();

        return ! $has;
    }

    private function useRootSsh(): bool
    {
        return (bool) config('server_firewall.use_root_ssh', true);
    }

    private function fallbackToDeployUserSsh(): bool
    {
        return (bool) config('server_firewall.fallback_to_deploy_user_ssh', true);
    }
}
