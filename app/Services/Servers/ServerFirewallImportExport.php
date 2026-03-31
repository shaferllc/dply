<?php

namespace App\Services\Servers;

use App\Livewire\Forms\FirewallRuleForm;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServerFirewallImportExport
{
    public function __construct(
        private ServerFirewallAuditLogger $audit,
    ) {}

    public function exportJson(Server $server): string
    {
        $server->load(['firewallRules' => fn ($q) => $q->orderBy('sort_order')]);
        $rules = $server->firewallRules->map(fn (ServerFirewallRule $r) => [
            'name' => $r->name,
            'port' => $r->port,
            'protocol' => $r->protocol,
            'source' => $r->source,
            'action' => $r->action,
            'enabled' => $r->enabled,
            'sort_order' => $r->sort_order,
            'profile' => $r->profile,
            'tags' => $r->tags,
            'runbook_url' => $r->runbook_url,
            'site_id' => $r->site_id,
        ])->values()->all();

        return json_encode([
            'version' => 2,
            'exported_at' => now()->toIso8601String(),
            'server_id' => $server->id,
            'server_name' => $server->name,
            'rules' => $rules,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * Replace all rules from exported JSON.
     */
    public function importJson(Server $server, string $json, ?User $user, bool $replace = true, ?ApiToken $apiToken = null): int
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['rules']) || ! is_array($data['rules'])) {
            throw new \InvalidArgumentException('Invalid firewall export JSON.');
        }

        $count = 0;

        DB::transaction(function () use ($server, $data, $user, $replace, &$count): void {
            if ($replace) {
                ServerFirewallRule::query()->where('server_id', $server->id)->delete();
            }

            $base = $replace ? 0 : (int) ($server->firewallRules()->max('sort_order') ?? 0);

            foreach ($data['rules'] as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $proto = (string) ($row['protocol'] ?? 'tcp');
                $port = $row['port'] ?? null;
                if (in_array(strtolower($proto), ['icmp', 'ipv6-icmp'], true)) {
                    $port = null;
                } elseif ($port !== null) {
                    $port = (int) $port;
                }

                $tags = $row['tags'] ?? null;
                if (is_string($tags)) {
                    $tags = FirewallRuleForm::tagsStringToArray($tags);
                } elseif (! is_array($tags)) {
                    $tags = null;
                }
                $siteId = isset($row['site_id']) && is_string($row['site_id']) ? $row['site_id'] : null;
                if ($siteId && ! $server->sites()->whereKey($siteId)->exists()) {
                    $siteId = null;
                }

                ServerFirewallRule::query()->create([
                    'server_id' => $server->id,
                    'site_id' => $siteId,
                    'name' => $row['name'] ?? null,
                    'profile' => isset($row['profile']) && is_string($row['profile']) ? substr($row['profile'], 0, 32) : null,
                    'tags' => is_array($tags) && $tags !== [] ? array_values($tags) : null,
                    'runbook_url' => isset($row['runbook_url']) && is_string($row['runbook_url']) ? $row['runbook_url'] : null,
                    'port' => $port,
                    'protocol' => $proto,
                    'source' => (string) ($row['source'] ?? 'any'),
                    'action' => in_array($row['action'] ?? 'allow', ['allow', 'deny'], true) ? $row['action'] : 'allow',
                    'enabled' => (bool) ($row['enabled'] ?? true),
                    'sort_order' => (int) ($row['sort_order'] ?? ($base + $i + 1)),
                ]);
                $count++;
            }

            $this->audit->record($server, ServerFirewallAuditEvent::EVENT_IMPORT, [
                'replace' => $replace,
                'imported_rules' => $count,
            ], $user, $apiToken);
        });

        return $count;
    }
}
