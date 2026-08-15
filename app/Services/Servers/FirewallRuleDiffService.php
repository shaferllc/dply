<?php

namespace App\Services\Servers;

use App\Models\Server;

class FirewallRuleDiffService
{
    public function __construct(
        private FirewallRuleStateHasher $hasher,
    ) {}

    /**
     * @return array{current_hash: string, last_apply_hash: ?string, matches_last_apply: bool, rule_count: int}
     */
    public function compareToLastApply(Server $server): array
    {
        $h = $this->hasher->hashServerRules($server);
        $meta = $server->meta ?? [];
        $last = $meta['firewall_last_apply_hash'] ?? null;

        return [
            'current_hash' => $h['hash'],
            'last_apply_hash' => is_string($last) ? $last : null,
            'matches_last_apply' => is_string($last) && $last === $h['hash'],
            'rule_count' => $h['count'],
        ];
    }
}
