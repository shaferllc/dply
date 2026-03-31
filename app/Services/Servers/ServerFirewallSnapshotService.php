<?php

namespace App\Services\Servers;

use App\Models\ApiToken;
use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Models\ServerFirewallSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServerFirewallSnapshotService
{
    public function __construct(
        private ServerFirewallAuditLogger $audit,
    ) {}

    public function create(Server $server, ?User $user, ?string $label = null, ?ApiToken $apiToken = null): ServerFirewallSnapshot
    {
        $server->load('firewallRules');
        $payload = $server->firewallRules->map(fn (ServerFirewallRule $r) => [
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

        $snap = ServerFirewallSnapshot::query()->create([
            'server_id' => $server->id,
            'user_id' => $user?->id,
            'label' => $label,
            'rules' => $payload,
        ]);

        $this->audit->record($server, ServerFirewallAuditEvent::EVENT_SNAPSHOT_CREATED, [
            'snapshot_id' => $snap->id,
            'label' => $label,
            'rule_count' => count($payload),
        ], $user, $apiToken);

        return $snap;
    }

    public function restore(Server $server, ServerFirewallSnapshot $snapshot, ?User $user, ?ApiToken $apiToken = null): void
    {
        if ($snapshot->server_id !== $server->id) {
            throw new \InvalidArgumentException('Snapshot belongs to another server.');
        }

        $rules = $snapshot->rules;
        if (! is_array($rules)) {
            throw new \InvalidArgumentException('Invalid snapshot payload.');
        }

        DB::transaction(function () use ($server, $rules, $snapshot, $user, $apiToken): void {
            ServerFirewallRule::query()->where('server_id', $server->id)->delete();
            foreach ($rules as $i => $row) {
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
                    'sort_order' => (int) ($row['sort_order'] ?? ($i + 1)),
                ]);
            }

            $this->audit->record($server, ServerFirewallAuditEvent::EVENT_SNAPSHOT_RESTORED, [
                'snapshot_id' => $snapshot->id,
                'label' => $snapshot->label,
            ], $user, $apiToken);
        });
    }
}
