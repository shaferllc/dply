<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\FirewallRuleTemplate;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Services\Servers\FirewallRuleTemplateApplicator;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    /**
     * Remove the rules a bundled template added, from the panel. Mirrors
     * {@see deleteFirewallRule()}: matches by (port, protocol, action, source)
     * and goes through the same panel-delete + host-removal path the rest of
     * the firewall UI uses. Rules aren't tagged by origin preset, so we match
     * structurally — but the SSH lifeline rule (configured ssh_port, tcp,
     * allow, from any) is ALWAYS skipped so a click can never lock the
     * operator out. The operator must remove SSH manually if they truly want
     * it gone.
     */
    public function removeBundledFirewallTemplate(string $key, ServerFirewallProvisioner $firewall, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        $bundled = config('server_firewall.bundled_templates', []);
        $bundle = $bundled[$key] ?? null;
        if (! is_array($bundle) || ! is_array($bundle['rules'] ?? null) || $bundle['rules'] === []) {
            $this->toastError(__('Unknown bundled firewall template.'));

            return;
        }

        $sshPort = (int) ($this->server->ssh_port ?: 22);

        $removed = 0;
        $skippedSsh = false;
        $this->server->refresh();
        $opsReady = $this->opsReady();

        foreach ($bundle['rules'] as $def) {
            if (! is_array($def)) {
                continue;
            }

            $port = isset($def['port']) && is_numeric($def['port']) ? (int) $def['port'] : null;
            $proto = strtolower((string) ($def['protocol'] ?? 'tcp'));
            $action = strtolower((string) ($def['action'] ?? 'allow'));
            $source = strtolower(trim((string) ($def['source'] ?? 'any')));

            // SAFETY: never remove the SSH management lifeline.
            $isSsh = $action === 'allow'
                && $proto === 'tcp'
                && $port === $sshPort
                && in_array($source, ['any', '0.0.0.0/0', '::/0'], true);
            if ($isSsh) {
                $skippedSsh = true;

                continue;
            }

            $matches = ServerFirewallRule::query()
                ->where('server_id', $this->server->id)
                ->where('port', $port)
                ->whereRaw('LOWER(protocol) = ?', [$proto])
                ->whereRaw('LOWER(action) = ?', [$action])
                ->whereRaw('LOWER(TRIM(source)) = ?', [$source])
                ->get();

            foreach ($matches as $rule) {
                if ($opsReady && $rule->enabled) {
                    try {
                        $firewall->removeFromHost($this->server, $rule);
                    } catch (\Throwable $e) {
                        // Host reconcile happens on the next Apply regardless.
                    }
                }

                $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_DELETED, [
                    'rule_id' => (string) $rule->id,
                    'via' => 'bundle_remove:'.$key,
                ], auth()->user());

                if ($this->editing_rule_id === (string) $rule->id) {
                    $this->cancelEditRule();
                }

                $rule->delete();
                $removed++;
            }
        }

        if ($removed === 0) {
            if ($skippedSsh) {
                $this->toastSuccess(__('Nothing removed — the SSH rule is kept to avoid locking you out. Remove it from the rules list manually if you really need to.'));
            } else {
                $this->toastSuccess(__('No matching rules to remove.'));
            }

            return;
        }

        $this->emitPanelEvent(
            __('Bundle rules removed — apply to push to the server'),
            array_values(array_filter([
                sprintf('> Removed %d rule(s) from the "%s" bundle.', $removed, $bundle['label'] ?? $key),
                $skippedSsh ? '  Kept the SSH allow rule to avoid lockout.' : null,
                '> Click "Apply rules" to reconcile the host firewall.',
            ])),
        );

        $msg = __('Removed :n rule(s) from the bundle.', ['n' => $removed]);
        if ($skippedSsh) {
            $msg .= ' '.__('Kept SSH to avoid lockout.');
        }
        $this->toastSuccess(Str::limit($msg, 500));
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
        $this->dispatch('close-modal', 'save-firewall-template-modal');
        $this->toastSuccess(__('Template saved.'));
    }
}
