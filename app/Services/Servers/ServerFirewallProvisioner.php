<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Services\SshConnection;

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

        $ssh = new SshConnection($server);
        $log = "Applying UFW rules (ensure SSH is reachable before tightening UFW).\n";

        foreach ($rules as $rule) {
            $log .= $this->applyRuleViaSsh($ssh, $server, $rule);
        }

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

        $ssh = new SshConnection($server);

        return $this->applyRuleViaSsh($ssh, $server, $rule);
    }

    /**
     * Full shell lines (with sudo when needed) that would be run for each enabled rule — for preview / dry-run.
     *
     * @return list<string>
     */
    public function previewApplyCommands(Server $server): array
    {
        $out = [];
        foreach ($server->firewallRules()->where('enabled', true)->orderBy('sort_order')->get() as $rule) {
            $out[] = $this->previewShellLine($server, $this->ufwRuleFragment($rule));
        }

        return $out;
    }

    public function previewDeleteCommand(Server $server, ServerFirewallRule $rule): string
    {
        return $this->previewShellLine($server, 'delete '.$this->ufwRuleFragment($rule));
    }

    /**
     * Shell line as executed over SSH (includes stderr redirect).
     */
    public function previewShellLine(Server $server, string $ufwArguments): string
    {
        return $this->ufwExecLine($server, $ufwArguments);
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

    private function applyRuleViaSsh(SshConnection $ssh, Server $server, ServerFirewallRule $rule): string
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

        $ssh = new SshConnection($server);
        $fragment = $this->ufwRuleFragment($rule);

        return $ssh->exec($this->ufwExecLine($server, 'delete '.$fragment), 60);
    }

    public function status(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $ssh = new SshConnection($server);

        return trim($ssh->exec($this->ufwExecLine($server, 'status verbose'), 60));
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

    /**
     * Read-only snapshot of iptables counters (first rows). Guarded by config — can be heavy / sensitive.
     */
    public function iptablesCountersSnapshot(Server $server): string
    {
        if (! config('server_firewall.danger_zone.iptables_counters_enabled', false)) {
            return 'Disabled in config (server_firewall.danger_zone.iptables_counters_enabled).';
        }
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $ssh = new SshConnection($server);
        $prefix = $this->ufwBinaryPrefix($server);
        $sudo = str_starts_with($prefix, 'sudo') ? 'sudo -n ' : '';

        return trim($ssh->exec($sudo.'iptables -L -n -v 2>&1 | head -n 80', 45));
    }
}
