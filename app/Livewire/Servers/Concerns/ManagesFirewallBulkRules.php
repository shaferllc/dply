<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesFirewallBulkRules
{
    public function selectAllFirewallRules(): void
    {
        $this->authorize('update', $this->server);
        $this->server->refresh();
        $this->firewall_bulk_ids = $this->server->firewallRules()->pluck('id')->all();
    }

    public function clearFirewallBulkSelection(): void
    {
        $this->firewall_bulk_ids = [];
    }

    public function bulkEnableFirewallRules(ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $ids = array_values(array_unique(array_filter($this->firewall_bulk_ids)));
        if ($ids === []) {
            $this->toastError(__('Select at least one rule.'));

            return;
        }
        $n = ServerFirewallRule::query()
            ->where('server_id', $this->server->id)
            ->whereIn('id', $ids)
            ->update(['enabled' => true]);
        $this->firewall_bulk_ids = [];
        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_UPDATED, [
            'bulk' => 'enable',
            'count' => $n,
        ], auth()->user());
        if ($n > 0) {
            $this->dispatchFirewallNotification('updated', [trans_choice('{1} :count rule|[2,*] :count rules', $n, ['count' => $n])], ['bulk' => 'enable', 'count' => $n]);
        }
        $this->toastSuccess(__('Enabled :n rule(s) in the panel. Use “Apply firewall rules” to sync the host.', ['n' => $n]));
    }

    public function bulkDisableFirewallRules(ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $ids = array_values(array_unique(array_filter($this->firewall_bulk_ids)));
        if ($ids === []) {
            $this->toastError(__('Select at least one rule.'));

            return;
        }
        $n = ServerFirewallRule::query()
            ->where('server_id', $this->server->id)
            ->whereIn('id', $ids)
            ->update(['enabled' => false]);
        $this->firewall_bulk_ids = [];
        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_UPDATED, [
            'bulk' => 'disable',
            'count' => $n,
        ], auth()->user());
        if ($n > 0) {
            $this->dispatchFirewallNotification('updated', [trans_choice('{1} :count rule|[2,*] :count rules', $n, ['count' => $n])], ['bulk' => 'disable', 'count' => $n]);
        }
        $this->toastSuccess(__('Disabled :n rule(s) in the panel. Use “Apply firewall rules” to sync the host.', ['n' => $n]));
    }

    public function bulkDeleteFirewallRules(ServerFirewallProvisioner $firewall, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $ids = array_values(array_unique(array_filter($this->firewall_bulk_ids)));
        if ($ids === []) {
            $this->toastError(__('Select at least one rule.'));

            return;
        }
        $rules = ServerFirewallRule::query()
            ->where('server_id', $this->server->id)
            ->whereIn('id', $ids)
            ->get();
        if ($rules->isEmpty()) {
            $this->firewall_bulk_ids = [];

            return;
        }
        $ruleIds = $rules->pluck('id')->all();
        foreach ($rules as $rule) {
            if ($this->opsReady() && $rule->enabled) {
                try {
                    $this->server->refresh();
                    $firewall->removeFromHost($this->server, $rule);
                } catch (\Throwable) {
                }
            }
            if ($this->editing_rule_id === $rule->id) {
                $this->cancelEditRule();
            }
            $rule->delete();
        }
        $this->firewall_bulk_ids = [];
        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_DELETED, [
            'bulk' => true,
            'rule_ids' => $ruleIds,
            'count' => count($ruleIds),
        ], auth()->user());
        $this->dispatchFirewallNotification('deleted', [trans_choice('{1} :count rule|[2,*] :count rules', count($ruleIds), ['count' => count($ruleIds)])], ['bulk' => true, 'rule_ids' => $ruleIds, 'count' => count($ruleIds)]);
        $this->toastSuccess(__('Removed :n rule(s).', ['n' => count($ruleIds)]));
    }

    public function trimDuplicateFirewallRules(ServerFirewallProvisioner $firewall, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        $rules = $this->server->firewallRules()->orderBy('sort_order')->orderBy('id')->get();
        if ($rules->isEmpty()) {
            $this->toastError(__('There are no firewall rules to trim.'));

            return;
        }

        $duplicates = $rules
            ->groupBy(fn (ServerFirewallRule $rule) => implode('|', [
                $rule->protocol,
                $rule->port ?? '',
                $rule->source,
                $rule->action,
            ]))
            ->flatMap(function ($group) {
                if ($group->count() < 2) {
                    return [];
                }

                $ordered = $group->sortBy([
                    ['enabled', 'desc'],
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])->values();

                return $ordered->slice(1);
            })
            ->values();

        if ($duplicates->isEmpty()) {
            $this->toastSuccess(__('No duplicate firewall rules were found.'));

            return;
        }

        $removedRuleIds = [];

        foreach ($duplicates as $rule) {
            if ($this->opsReady() && $rule->enabled) {
                try {
                    $this->server->refresh();
                    $firewall->removeFromHost($this->server, $rule);
                } catch (\Throwable) {
                }
            }

            if ($this->editing_rule_id === $rule->id) {
                $this->cancelEditRule();
            }

            $removedRuleIds[] = $rule->id;
            $rule->delete();
        }

        $this->firewall_bulk_ids = array_values(array_diff($this->firewall_bulk_ids, $removedRuleIds));

        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_DELETED, [
            'bulk' => 'trim_duplicates',
            'rule_ids' => $removedRuleIds,
            'count' => count($removedRuleIds),
        ], auth()->user());

        $this->toastSuccess(__('Trimmed :n duplicate rule(s).', ['n' => count($removedRuleIds)]));
    }
}
