<?php

namespace App\Services\Servers;

use App\Jobs\ApplyFirewallJob;
use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Modules\Cloud\Services\HetznerService;
use App\Support\Servers\HetznerCloudFirewallRules;
use Illuminate\Support\Facades\Log;

class ServerFirewallProvisioner
{
    /**
     * Optional output callback set by callers that want per-command streaming during apply.
     * The callback receives one line at a time — usually a `> ufw …` header before the
     * exec and the captured output indented after. Used by {@see ApplyFirewallJob}
     * to surface live progress in the workspace banner; in-request callers leave it null
     * and get the same buffered string return value as before.
     *
     * @var (callable(string, string): void)|null
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
     *
     * Action `limit` maps to UFW's per-source rate-limit gate — the canonical SSH brute-force
     * mitigation. UFW only supports `limit` over TCP; the form validates that combination, but
     * we still narrow the verb here defensively in case a legacy row slipped in.
     *
     * When `app_profile` is set on the rule, the fragment is just `<verb> <profile>` — UFW reads
     * /etc/ufw/applications.d to expand the port/protocol set, so we MUST NOT also emit a port.
     */
    public function ufwRuleFragment(ServerFirewallRule $rule): string
    {
        // Interface scoping is independent of the protocol/profile branches — UFW's grammar puts
        // `in on <iface>` / `out on <iface>` between the verb and the rest of the rule. We slot
        // it in by computing the segment once and threading it through every branch.
        $ifaceSegment = $this->ufwIfaceSegment($rule);

        $appProfile = trim((string) ($rule->app_profile ?? ''));
        if ($appProfile !== '') {
            $verb = $this->ufwVerbFor($rule->action, 'tcp');
            // UFW app profiles support `from <src>` qualifiers, same as port-form rules.
            $source = strtolower(trim((string) $rule->source));
            if ($source === '' || $source === 'any') {
                return $this->joinUfwSegments([$verb, $ifaceSegment, $appProfile]);
            }

            return $this->joinUfwSegments([$verb, $ifaceSegment, sprintf('from %s to any app %s', trim((string) $rule->source), $appProfile)]);
        }

        $p = strtolower(trim((string) $rule->protocol));

        if (in_array($p, ['icmp', 'ipv6-icmp'], true)) {
            $verb = $this->ufwVerbFor($rule->action, $p);
            $ufwProto = $p === 'ipv6-icmp' ? 'ipv6-icmp' : 'icmp';
            $source = strtolower(trim((string) $rule->source));
            if ($source === '' || $source === 'any') {
                return $this->joinUfwSegments([$verb, $ifaceSegment, sprintf('proto %s', $ufwProto)]);
            }
            $src = trim((string) $rule->source);

            return $this->joinUfwSegments([$verb, $ifaceSegment, sprintf('from %s proto %s', $src, $ufwProto)]);
        }

        $proto = in_array($rule->protocol, ['tcp', 'udp'], true) ? $rule->protocol : 'tcp';
        if ($rule->port === null) {
            throw new \InvalidArgumentException('TCP/UDP rules require a port.');
        }
        $port = (int) $rule->port;
        $verb = $this->ufwVerbFor($rule->action, $proto);
        $source = strtolower(trim((string) $rule->source));

        if ($source === '' || $source === 'any') {
            return $this->joinUfwSegments([$verb, $ifaceSegment, sprintf('%d/%s', $port, $proto)]);
        }

        $src = trim((string) $rule->source);

        return $this->joinUfwSegments([$verb, $ifaceSegment, sprintf('from %s to any port %d proto %s', $src, $port, $proto)]);
    }

    /**
     * Build the `<direction> on <iface>` segment when the rule is interface-scoped, or empty
     * string if not. UFW only accepts `in` and `out`; we silently drop unknown directions so a
     * legacy half-set row (iface present, direction blank) doesn't break apply.
     */
    private function ufwIfaceSegment(ServerFirewallRule $rule): string
    {
        $iface = trim((string) ($rule->iface ?? ''));
        if ($iface === '') {
            return '';
        }
        $direction = strtolower(trim((string) ($rule->iface_direction ?? 'in')));
        if (! in_array($direction, ['in', 'out'], true)) {
            return '';
        }

        return sprintf('%s on %s', $direction, $iface);
    }

    /**
     * Join non-empty UFW grammar segments with single spaces. Skipping empties lets the iface
     * branch be optional without sprinkling conditionals through every return path.
     *
     * @param  array<string, mixed> $segments
     */
    private function joinUfwSegments(array $segments): string
    {
        return implode(' ', array_filter(array_map('trim', $segments), static fn ($s) => $s !== ''));
    }

    /**
     * Read the server's desired UFW logging level from meta. Returns one of `off|low|medium|high|full`
     * or `null` to mean "leave the host alone."
     */
    public function loggingLevelFromMeta(Server $server): ?string
    {
        $meta = $server->meta ?? [];
        $key = (string) config('server_firewall.meta_logging_level_key', 'firewall_logging_level');
        $value = $meta[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        $allowed = (array) config('server_firewall.logging_levels', ['off', 'low', 'medium', 'high', 'full']);

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Read the server's per-chain default policies from meta. Returns an associative array keyed
     * by chain (`incoming`/`outgoing`/`routed`) → policy (`allow`/`deny`/`reject`). Only chains
     * that have an explicit value set are returned — chains the operator hasn't touched stay at
     * UFW's defaults, which is what an existing server expects.
     *
     * @return array<string, string>
     */
    public function defaultPoliciesFromMeta(Server $server): array
    {
        $meta = $server->meta ?? [];
        $allowed = (array) config('server_firewall.default_policies', ['allow', 'deny', 'reject']);
        $keys = [
            'incoming' => (string) config('server_firewall.meta_default_incoming_key', 'firewall_default_incoming'),
            'outgoing' => (string) config('server_firewall.meta_default_outgoing_key', 'firewall_default_outgoing'),
            'routed' => (string) config('server_firewall.meta_default_routed_key', 'firewall_default_routed'),
        ];

        $out = [];
        foreach ($keys as $chain => $metaKey) {
            $value = $meta[$metaKey] ?? null;
            if (! is_string($value)) {
                continue;
            }
            $value = strtolower(trim($value));
            if (! in_array($value, $allowed, true)) {
                continue;
            }
            $out[$chain] = $value;
        }

        return $out;
    }

    /**
     * UFW only supports `limit` on TCP. For any other protocol it silently downgrades to `allow`
     * so an operator who manually inserted a bad row doesn't trip the apply transaction.
     */
    private function ufwVerbFor(?string $action, string $protocol): string
    {
        $a = strtolower(trim((string) $action));

        return match (true) {
            $a === 'deny' => 'deny',
            $a === 'limit' && $protocol === 'tcp' => 'limit',
            $a === 'limit' => 'allow',
            default => 'allow',
        };
    }

    public function apply(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $rules = $server->firewallRules()->where('enabled', true)->orderBy('sort_order')->get();
        $defaultPolicies = $this->defaultPoliciesFromMeta($server);
        $loggingLevel = $this->loggingLevelFromMeta($server);
        if ($rules->isEmpty() && $defaultPolicies === [] && $loggingLevel === null) {
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
            function ($ssh) use ($rules, $server, $sshPort, $defaultPolicies, $loggingLevel): string {
                $output = '';

                // Defaults go BEFORE rules so a tightened "default deny incoming" is in place
                // before per-rule allows reopen specific ports. UFW writes the value to
                // /etc/default/ufw; the `--force enable` at the bottom reloads with the new
                // settings.
                foreach ($defaultPolicies as $chain => $policy) {
                    $fragment = sprintf('default %s %s', $policy, $chain);
                    $this->emitOutput('> ufw '.$fragment);
                    $out = $ssh->exec($this->ufwExecLine($server, $fragment), 30);
                    $this->emitIndented($out);
                    $output .= $out;
                }

                if ($loggingLevel !== null) {
                    $fragment = sprintf('logging %s', $loggingLevel);
                    $this->emitOutput('> ufw '.$fragment);
                    $out = $ssh->exec($this->ufwExecLine($server, $fragment), 30);
                    $this->emitIndented($out);
                    $output .= $out;
                }

                $sshGuardFragment = 'allow '.$sshPort.'/tcp comment '.escapeshellarg('Dply: keep SSH reachable');
                $this->emitOutput('> ufw '.$sshGuardFragment);
                $sshGuardOut = $ssh->exec($this->ufwExecLine($server, $sshGuardFragment), 60);
                $this->emitIndented($sshGuardOut);
                $output .= $sshGuardOut;

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

        $result = app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $this->applyRuleViaSsh($ssh, $server, $rule),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );

        $this->syncHetznerCloudFirewall($server);

        return $result;
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

        $result = app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($this->ufwExecLine($server, 'delete '.$fragment), 60),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );

        $this->syncHetznerCloudFirewall($server);

        return $result;
    }

    /**
     * After any UFW mutation on a Hetzner server, push the updated rule set to the
     * Hetzner Cloud Firewall so the edge firewall matches the on-box UFW state.
     * Best-effort — a Hetzner API failure never blocks the UFW operation.
     */
    private function syncHetznerCloudFirewall(Server $server): void
    {
        if ($server->provider->value !== 'hetzner') {
            return;
        }

        $firewallId = (int) data_get($server->meta, 'hetzner_firewall_id', 0);
        if ($firewallId === 0) {
            return;
        }

        $credential = $server->providerCredential;
        if (! $credential) {
            return;
        }

        try {
            $hetzner = new HetznerService($credential);
            $rules = HetznerCloudFirewallRules::forServer($server->fresh() ?? $server);
            $hetzner->setFirewallRules($firewallId, $rules);
        } catch (\Throwable $e) {
            Log::warning('ServerFirewallProvisioner: Hetzner cloud firewall sync failed', [
                'server_id' => $server->id,
                'firewall_id' => $firewallId,
                'error' => $e->getMessage(),
            ]);
        }
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
        // Trailing `comment '...'` and route/log prefixes aren't structured rules — drop them
        // rather than guessing. `limit` IS structured (it's just a rate-limited allow), so we
        // let it fall through to the action regex below.
        $stripped = preg_replace('/\s+comment\s+([\'"]).*?\1\s*$/', '', $stripped) ?? $stripped;
        if (preg_match('/^(route|log)\b/i', $stripped) === 1) {
            return $unknown;
        }

        if (! preg_match('/^(allow|deny|limit)\b\s*(.*)$/i', $stripped, $m)) {
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
