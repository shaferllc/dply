<?php

namespace App\Services\Servers;

use App\Livewire\Forms\FirewallRuleForm;
use App\Models\ApiToken;
use App\Models\FirewallRuleTemplate;
use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Models\User;

class FirewallRuleTemplateApplicator
{
    public function __construct(
        private ServerFirewallAuditLogger $audit,
    ) {}

    /**
     * Apply a config key from server_firewall.bundled_templates.
     *
     * @return int Number of rules created
     */
    public function applyBundled(Server $server, string $key, ?User $user, ?ApiToken $apiToken = null): int
    {
        $bundled = config('server_firewall.bundled_templates', []);
        if (! isset($bundled[$key]) || ! is_array($bundled[$key]['rules'] ?? null)) {
            throw new \InvalidArgumentException('Unknown bundled firewall template.');
        }

        return $this->createRulesFromDefinitions($server, $bundled[$key]['rules'], $user, [
            'template' => 'bundled:'.$key,
            'label' => $bundled[$key]['label'] ?? $key,
        ], $apiToken);
    }

    /**
     * @return int Number of rules created
     */
    public function applyDatabaseTemplate(Server $server, FirewallRuleTemplate $template, ?User $user, ?ApiToken $apiToken = null): int
    {
        if ($template->organization_id !== $server->organization_id) {
            throw new \InvalidArgumentException('Template does not belong to this server’s organization.');
        }
        if ($template->server_id !== null && $template->server_id !== $server->id) {
            throw new \InvalidArgumentException('Template is scoped to a different server.');
        }

        $rules = $template->rules;
        if (! is_array($rules)) {
            throw new \InvalidArgumentException('Invalid template rules payload.');
        }

        return $this->createRulesFromDefinitions($server, $rules, $user, [
            'template_id' => $template->id,
            'template_name' => $template->name,
        ], $apiToken);
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $auditMeta
     */
    private function createRulesFromDefinitions(Server $server, array $definitions, ?User $user, array $auditMeta, ?ApiToken $apiToken = null): int
    {
        $n = 0;
        $baseOrder = (int) ($server->firewallRules()->max('sort_order') ?? 0);

        foreach ($definitions as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $proto = strtolower((string) ($row['protocol'] ?? 'tcp'));
            $port = $row['port'] ?? null;
            if (in_array($proto, ['icmp', 'ipv6-icmp'], true)) {
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
                'name' => isset($row['name']) ? (string) $row['name'] : null,
                'profile' => isset($row['profile']) && is_string($row['profile']) ? substr($row['profile'], 0, 32) : null,
                'tags' => is_array($tags) && $tags !== [] ? array_values($tags) : null,
                'runbook_url' => isset($row['runbook_url']) && is_string($row['runbook_url']) ? $row['runbook_url'] : null,
                'port' => $port,
                'protocol' => (string) ($row['protocol'] ?? 'tcp'),
                'source' => (string) ($row['source'] ?? 'any'),
                'action' => in_array($row['action'] ?? 'allow', ['allow', 'deny'], true) ? $row['action'] : 'allow',
                'enabled' => (bool) ($row['enabled'] ?? true),
                'sort_order' => $baseOrder + $i + 1,
            ]);
            $n++;
        }

        if ($n > 0) {
            $this->audit->record($server, ServerFirewallAuditEvent::EVENT_TEMPLATE_APPLIED, array_merge($auditMeta, [
                'rules_added' => $n,
            ]), $user, $apiToken);
        }

        return $n;
    }
}
