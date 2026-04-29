<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\FirewallRuleTemplate;
use App\Services\Servers\FirewallRuleTemplateApplicator;

trait ManagesFirewallWorkspaceAdvanced
{
    /** @var 'rules'|'templates'|'history'|'audit' */
    public string $firewall_workspace_tab = 'rules';

    public bool $firewall_ack_ssh_risk = false;

    /** @var list<string> */
    public array $firewall_bulk_ids = [];

    public string $new_saved_template_name = '';

    public ?string $new_saved_template_description = null;

    /** org = organization-wide; server = this server only */
    public string $new_saved_template_scope = 'org';

    public function applyBundledFirewallTemplate(string $key): void
    {
        $this->authorize('update', $this->server);

        try {
            $n = app(FirewallRuleTemplateApplicator::class)->applyBundled($this->server, $key, auth()->user());
            $this->toastSuccess(__('Added :n rule(s) from the bundle.', ['n' => $n]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function applySavedFirewallTemplate(string $templateId): void
    {
        $this->authorize('update', $this->server);

        $tpl = FirewallRuleTemplate::query()
            ->where('organization_id', $this->server->organization_id)
            ->where(function ($q) {
                $q->whereNull('server_id')->orWhere('server_id', $this->server->id);
            })
            ->whereKey($templateId)
            ->firstOrFail();

        try {
            $n = app(FirewallRuleTemplateApplicator::class)->applyDatabaseTemplate($this->server, $tpl, auth()->user());
            $this->toastSuccess(__('Added :n rule(s) from template “:name”.', ['n' => $n, 'name' => $tpl->name]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function saveCurrentRulesAsTemplate(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'new_saved_template_name' => 'required|string|max:160',
            'new_saved_template_description' => 'nullable|string|max:500',
            'new_saved_template_scope' => 'required|in:org,server',
        ]);

        $this->server->load(['firewallRules' => fn ($q) => $q->orderBy('sort_order')]);

        $rules = $this->server->firewallRules->map(fn ($r) => [
            'name' => $r->name,
            'port' => $r->port,
            'protocol' => $r->protocol,
            'source' => $r->source,
            'action' => $r->action,
            'enabled' => $r->enabled,
            'profile' => $r->profile,
            'tags' => $r->tags,
            'runbook_url' => $r->runbook_url,
            'site_id' => $r->site_id,
        ])->values()->all();

        FirewallRuleTemplate::query()->create([
            'organization_id' => $this->server->organization_id,
            'server_id' => $this->new_saved_template_scope === 'server' ? $this->server->id : null,
            'name' => trim($this->new_saved_template_name),
            'description' => $this->new_saved_template_description ? trim($this->new_saved_template_description) : null,
            'rules' => $rules,
        ]);

        $this->new_saved_template_name = '';
        $this->new_saved_template_description = null;
        $this->toastSuccess(__('Template saved.'));
    }
}
