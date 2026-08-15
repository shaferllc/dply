<?php

namespace App\Services\Servers;

use App\Models\Server;

class FirewallRuleStateHasher
{
    /**
     * Stable hash of the current ordered rule set (for ETag / drift / “last applied”).
     *
     * @return array{hash: string, count: int}
     */
    public function hashServerRules(Server $server): array
    {
        $server->load(['firewallRules' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);
        $payload = $server->firewallRules->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'port' => $r->port,
            'protocol' => $r->protocol,
            'source' => $r->source,
            'action' => $r->action,
            'enabled' => $r->enabled,
            'sort_order' => $r->sort_order,
            'profile' => $r->profile ?? null,
            'tags' => $r->tags ?? null,
            'runbook_url' => $r->runbook_url ?? null,
            'site_id' => $r->site_id ?? null,
        ])->values()->all();

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'hash' => hash('sha256', $json),
            'count' => count($payload),
        ];
    }
}
