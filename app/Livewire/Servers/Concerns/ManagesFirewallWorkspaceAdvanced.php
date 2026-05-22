<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\FirewallRuleTemplate;
use App\Models\ServerFirewallRule;
use App\Services\Servers\FirewallRuleTemplateApplicator;
use Illuminate\Support\Collection;

trait ManagesFirewallWorkspaceAdvanced
{
    /** @var 'rules'|'templates'|'activity' */
    public string $firewall_workspace_tab = 'rules';

    public bool $firewall_ack_ssh_risk = false;

    /** @var list<string> */
    public array $firewall_bulk_ids = [];

    /**
     * Free-text search applied to the rules table — matches name, port (as string),
     * source, profile, app_profile, iface, and tag values. Empty means "show all".
     */
    public string $rule_filter = '';

    /** Empty string means "all actions"; otherwise one of allow|deny|limit. */
    public string $rule_filter_action = '';

    public string $new_saved_template_name = '';

    public ?string $new_saved_template_description = null;

    /** org = organization-wide; server = this server only */
    public string $new_saved_template_scope = 'org';

    /**
     * Apply $rule_filter / $rule_filter_action to a rule collection. Pulled into the trait so
     * both the view (which paints filtered rows) and any future bulk-on-filtered action share
     * one matcher. Case-insensitive, treats blank filter as "match anything".
     *
     * @param  Collection<int, ServerFirewallRule>  $rules
     * @return Collection<int, ServerFirewallRule>
     */
    public function filteredFirewallRules($rules)
    {
        $needle = strtolower(trim($this->rule_filter));
        $actionFilter = strtolower(trim($this->rule_filter_action));

        return $rules->filter(function ($r) use ($needle, $actionFilter): bool {
            if ($actionFilter !== '' && strtolower((string) $r->action) !== $actionFilter) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            $haystack = strtolower(implode(' ', array_filter([
                (string) ($r->name ?? ''),
                (string) ($r->profile ?? ''),
                (string) ($r->app_profile ?? ''),
                (string) ($r->iface ?? ''),
                (string) ($r->source ?? ''),
                (string) ($r->port ?? ''),
                (string) ($r->protocol ?? ''),
                is_array($r->tags) ? implode(' ', $r->tags) : '',
            ])));

            return str_contains($haystack, $needle);
        })->values();
    }

    public function clearRuleFilter(): void
    {
        $this->rule_filter = '';
        $this->rule_filter_action = '';
    }

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
