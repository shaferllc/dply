<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerFirewallRule;

class ServerFirewallProvisioner
{
    /**
     * Optional output callback set by callers that want per-command streaming during apply.
     * The callback receives one line at a time — usually a `> ufw …` header before the
     * exec and the captured output indented after. Used by {@see \App\Jobs\ApplyFirewallJob}
     * to surface live progress in the workspace banner; in-request callers leave it null
     * and get the same buffered string return value as before.
     *
     * @var (callable(string $type, string $line): void)|null
     */
    protected $outputCallback = null;

    /**
     * @param  callable(string $type, string $line): void  $callback
     */
    public function withOutputCallback(callable $callback): static
    {
        $this->outputCallback = $callback;

        return $this;
    }

    protected function emitOutput(string $line): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)('out', $line);
        }
    }

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
            $this->emitOutput('> No enabled firewall rules to apply.');

            return 'No enabled firewall rules to apply.';
        }

        $log = "Applying UFW rules (ensure SSH is reachable before tightening UFW).\n";
        $this->emitOutput('> Applying UFW rules to '.$server->getSshConnectionString().' …');
        $this->emitOutput(sprintf('> %d enabled rule(s) to apply.', $rules->count()));

        // Safety rail: always allow the configured SSH port (whatever it is — 22 in the
        // common case, 2222 for the local fake-cloud container) so the user can never
        // lock themselves out by removing/disabling the SSH rule in the panel.
        $sshPort = (int) ($server->ssh_port ?: 22);

        $log .= app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($rules, $server, $sshPort): string {
                $sshGuardFragment = 'allow '.$sshPort.'/tcp comment '.escapeshellarg('Dply: keep SSH reachable');
                $this->emitOutput('> ufw '.$sshGuardFragment);
                $sshGuardOut = $ssh->exec($this->ufwExecLine($server, $sshGuardFragment), 60);
                $this->emitIndented($sshGuardOut);
                $output = $sshGuardOut;

                foreach ($rules as $rule) {
                    $fragment = $this->ufwRuleFragment($rule);
                    $this->emitOutput('> ufw '.$fragment.($rule->name ? '   # '.$rule->name : ''));
                    $ruleOut = $ssh->exec($this->ufwExecLine($server, $fragment), 60);
                    $this->emitIndented($ruleOut);
                    $output .= $ruleOut;
                }

                // Make sure UFW is actually loaded — fresh installs leave it inactive,
                // and rules with no enable are a silent no-op. `--force` skips the
                // "Command may disrupt existing ssh connections" prompt.
                $this->emitOutput('> ufw --force enable');
                $enableOut = $ssh->exec($this->ufwExecLine($server, '--force enable'), 30);
                $this->emitIndented($enableOut);
                $output .= $enableOut;

                return $output;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );

        $this->emitOutput('> Done.');

        return $log;
    }

    /**
     * Push a captured command output through the streaming callback as one indented line per
     * non-empty source line, so the workspace transcript reads as `> ufw allow 80/tcp` followed
     * by `  Rule added` rather than concatenating everything into the previous header.
     */
    protected function emitIndented(string $blob): void
    {
        if ($this->outputCallback === null) {
            return;
        }
        foreach (preg_split("/\r?\n/", trim($blob)) ?: [] as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            $this->emitOutput('  '.$line);
        }
    }

    /**
     * Run a curated set of read-only UFW/iptables commands for diagnostics. Returns a single
     * combined log so the workspace can render it in one console panel.
     */
    public function diagnostics(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $iptablesPrefix = $this->privilegedBinaryPrefix($server, 'iptables');
        $commands = [
            ['label' => 'ufw status verbose', 'cmd' => $this->ufwExecLine($server, 'status verbose')],
            ['label' => 'ufw status numbered', 'cmd' => $this->ufwExecLine($server, 'status numbered')],
            ['label' => 'ss -ltn (listening TCP)', 'cmd' => 'ss -ltn 2>&1 || netstat -ltn 2>&1'],
            ['label' => 'iptables -L INPUT -n -v --line-numbers (head)',
                'cmd' => $iptablesPrefix.' -L INPUT -n -v --line-numbers 2>&1 | head -40'],
        ];

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($commands): string {
                $out = '';
                foreach ($commands as $entry) {
                    $out .= str_repeat('═', 60)."\n";
                    $out .= '$ '.$entry['label']."\n";
                    $out .= str_repeat('═', 60)."\n";
                    $out .= $ssh->exec($entry['cmd'], 30);
                    $out .= "\n";
                }

                return $out;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
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
     * UFW lives in /usr/sbin which sudoers' default `secure_path` strips, so plain
     * `sudo -n ufw …` fails with "ufw: command not found". Re-set PATH inside the sudo
     * shell so /usr/sbin and /sbin are reachable for both ufw and iptables.
     */
    private function ufwBinaryPrefix(Server $server): string
    {
        $user = trim((string) $server->ssh_user);
        if ($user === '' || $user === 'root') {
            return 'ufw';
        }

        return 'sudo -n env PATH=/usr/sbin:/usr/bin:/sbin:/bin ufw';
    }

    /**
     * Same PATH-extending wrapper for any other privileged binary we need to run via sudo
     * (iptables, etc.). Returns the prefix the caller appends arguments to.
     */
    private function privilegedBinaryPrefix(Server $server, string $binary): string
    {
        $user = trim((string) $server->ssh_user);
        if ($user === '' || $user === 'root') {
            return $binary;
        }

        return 'sudo -n env PATH=/usr/sbin:/usr/bin:/sbin:/bin '.$binary;
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
     * Pull the host's user-added UFW rules via `ufw show added` and parse each line into a
     * structured rule we can reconcile against the panel. Lines we can't confidently parse are
     * returned with a non-null `raw` and null structured fields so the caller can render them
     * as "skipped" in the import preview.
     *
     * @return list<array{action: ?string, port: ?int, protocol: ?string, source: ?string, raw: string}>
     */
    public function importableRulesFromHost(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $blob = app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => trim($ssh->exec($this->ufwExecLine($server, 'show added'), 30)),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );

        $rules = [];
        foreach (preg_split("/\r?\n/", $blob) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, 'ufw ')) {
                continue;
            }
            $rules[] = $this->parseUfwShowAddedLine($line);
        }

        return $rules;
    }

    /**
     * Parse one `ufw show added` line (e.g. `ufw allow 80/tcp`, `ufw deny from 10.0.0.0/8 to any
     * port 22 proto tcp`, `ufw allow proto icmp`) into structured fields. Returns `raw` only
     * when the line doesn't match a shape we know how to import.
     *
     * @return array{action: ?string, port: ?int, protocol: ?string, source: ?string, raw: string}
     */
    public function parseUfwShowAddedLine(string $line): array
    {
        $unknown = ['action' => null, 'port' => null, 'protocol' => null, 'source' => null, 'raw' => $line];

        $stripped = preg_replace('/^ufw\s+/', '', $line, 1);
        if (! is_string($stripped) || $stripped === '') {
            return $unknown;
        }
        // Trailing `comment '...'` and route/log/limit prefixes aren't structured rules — drop
        // them rather than guessing.
        $stripped = preg_replace('/\s+comment\s+([\'"]).*?\1\s*$/', '', $stripped) ?? $stripped;
        if (preg_match('/^(route|log|limit)\b/i', $stripped) === 1) {
            return $unknown;
        }

        if (! preg_match('/^(allow|deny)\b\s*(.*)$/i', $stripped, $m)) {
            return $unknown;
        }
        $action = strtolower($m[1]);
        $rest = trim($m[2]);

        // ICMP/ICMPv6: `allow proto icmp` (optionally `from <src>`).
        if (preg_match('/^(?:from\s+(\S+)\s+)?proto\s+(icmp|ipv6-icmp)\s*$/i', $rest, $im)) {
            return [
                'action' => $action,
                'port' => null,
                'protocol' => strtolower($im[2]),
                'source' => $im[1] !== '' ? $im[1] : 'any',
                'raw' => $line,
            ];
        }

        // Long form: `from <src> to any port <port> proto <tcp|udp>` (proto optional, defaults to tcp).
        if (preg_match('/^from\s+(\S+)\s+to\s+\S+\s+port\s+(\d+)(?:\s+proto\s+(tcp|udp))?\s*$/i', $rest, $lm)) {
            return [
                'action' => $action,
                'port' => (int) $lm[2],
                'protocol' => strtolower($lm[3] ?? 'tcp'),
                'source' => $lm[1],
                'raw' => $line,
            ];
        }

        // Short form: `<port>/<proto>` or just `<port>` (proto defaults to tcp).
        if (preg_match('#^(\d+)(?:/(tcp|udp))?\s*$#i', $rest, $sm)) {
            return [
                'action' => $action,
                'port' => (int) $sm[1],
                'protocol' => strtolower($sm[2] ?? 'tcp'),
                'source' => 'any',
                'raw' => $line,
            ];
        }

        return $unknown;
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
