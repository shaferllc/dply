<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerFirewallRule;
use Illuminate\Support\Collection;

/**
 * Compares Dply-stored rules (desired) with a best-effort parse of `ufw status verbose`.
 */
class FirewallDriftAnalyzer
{
    /**
     * @return array{
     *   in_sync: bool,
     *   missing_on_host: list<string>,
     *   extra_on_host: list<string>,
     *   unmatched_db_rules: list<string>,
     *   notes: string
     * }
     */
    public function analyze(Server $server, string $ufwStatusVerbose, Collection $enabledRules): array
    {
        $hostSigs = $this->parseUfwStatus($ufwStatusVerbose);
        $dbSigs = $enabledRules->map(fn (ServerFirewallRule $r) => $this->ruleSignature($r))->filter()->values()->all();

        $dbSet = array_fill_keys($dbSigs, true);
        $hostSet = array_fill_keys($hostSigs, true);

        $missing = [];
        foreach ($dbSigs as $sig) {
            if (! isset($hostSet[$sig])) {
                $missing[] = $sig;
            }
        }

        $extra = [];
        foreach ($hostSigs as $sig) {
            if (! isset($dbSet[$sig])) {
                $extra[] = $sig;
            }
        }

        $notes = __('Drift detection matches common UFW “status verbose” rows (TCP/UDP ports and ICMP). App profiles (e.g. Nginx Full) and manual rules may appear as extras until mirrored in Dply.');

        return [
            'in_sync' => $missing === [] && $extra === [],
            'missing_on_host' => $missing,
            'extra_on_host' => $extra,
            'unmatched_db_rules' => $missing,
            'notes' => $notes,
        ];
    }

    /**
     * Normalized signature for comparison: action:port:proto:source or action::icmp|ipv6-icmp:source
     */
    public function ruleSignature(ServerFirewallRule $rule): ?string
    {
        $action = $rule->action === 'deny' ? 'deny' : 'allow';
        $src = strtolower(trim((string) $rule->source));
        $src = ($src === '' || $src === 'any') ? 'any' : $src;
        $p = strtolower(trim((string) $rule->protocol));

        if (in_array($p, ['icmp', 'ipv6-icmp'], true)) {
            return sprintf('%s::%s:%s', $action, $p, $src);
        }

        if (! in_array($p, ['tcp', 'udp'], true) || $rule->port === null) {
            return null;
        }

        return sprintf('%s:%d:%s:%s', $action, (int) $rule->port, $p, $src);
    }

    /**
     * @return list<string>
     */
    public function parseUfwStatus(string $raw): array
    {
        $sigs = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, 'Status:') || str_starts_with($line, 'Logging:')
                || str_starts_with($line, 'Default:') || $line === 'To' || str_contains($line, '----')) {
                continue;
            }

            if (preg_match('/^\s*(\d+)\/(tcp|udp)\s+(ALLOW|DENY)\s+(.+?)\s*$/i', $line, $m)) {
                $action = strtolower($m[3]) === 'deny' ? 'deny' : 'allow';
                $from = $this->normalizeUfwFrom(trim($m[4]));
                $sigs[] = sprintf('%s:%d:%s:%s', $action, (int) $m[1], strtolower($m[2]), $from);

                continue;
            }

            if (preg_match('/^\s*(icmp|ipv6-icmp)\s+(ALLOW|DENY)\s+(.+?)\s*$/i', $line, $m)) {
                $action = strtolower($m[2]) === 'deny' ? 'deny' : 'allow';
                $proto = strtolower($m[1]) === 'ipv6-icmp' ? 'ipv6-icmp' : 'icmp';
                $from = $this->normalizeUfwFrom(trim($m[3]));
                $sigs[] = sprintf('%s::%s:%s', $action, $proto, $from);
            }
        }

        return array_values(array_unique($sigs));
    }

    private function normalizeUfwFrom(string $from): string
    {
        $from = preg_replace('/\s*\(v6\)\s*$/i', '', $from) ?? $from;
        $from = trim($from);
        if ($from === '' || strcasecmp($from, 'Anywhere') === 0) {
            return 'any';
        }

        return $from;
    }
}
