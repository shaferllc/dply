<?php

namespace App\Livewire\Servers;

use App\Livewire\Forms\FirewallRuleForm;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesFirewallWorkspaceAdvanced;
use App\Models\FirewallRuleTemplate;
use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Services\Servers\FirewallDualApprovalService;
use App\Services\Servers\FirewallMaintenanceGate;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceFirewall extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesFirewallWorkspaceAdvanced;

    public FirewallRuleForm $form;

    public ?string $editing_rule_id = null;

    public ?string $ufw_status_text = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->form->resetForNew();
    }

    protected function opsReady(): bool
    {
        return $this->server->isReady() && ! empty($this->server->ssh_private_key);
    }

    public function startEditRule(string $id): void
    {
        $this->authorize('update', $this->server);
        $rule = ServerFirewallRule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();
        $this->editing_rule_id = $rule->id;
        $this->form->setForEdit($rule);
        $this->resetErrorBag();
    }

    public function cancelEditRule(): void
    {
        $this->editing_rule_id = null;
        $this->form->resetForNew();
        $this->resetErrorBag();
    }

    /**
     * Fill the add-rule form from config/server_firewall.php presets (HTTP, HTTPS, SSH, …).
     */
    public function useFirewallPreset(string $key): void
    {
        $this->authorize('update', $this->server);
        $this->form->applyPreset($key);
        $this->resetErrorBag();
    }

    public function saveFirewallRule(ServerFirewallProvisioner $firewall, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        if (in_array($this->form->protocol, ['icmp', 'ipv6-icmp'], true)) {
            $this->form->port = null;
        }
        $this->form->validate();
        if ($this->form->site_id) {
            $siteOk = $this->server->sites()->whereKey($this->form->site_id)->exists();
            if (! $siteOk) {
                $this->addError('form.site_id', __('Pick a site on this server or leave blank.'));

                return;
            }
        }
        $this->flash_error = null;

        $port = in_array($this->form->protocol, ['icmp', 'ipv6-icmp'], true) ? null : $this->form->port;
        $tags = FirewallRuleForm::tagsStringToArray($this->form->tags);
        $profile = $this->form->profile ? trim((string) $this->form->profile) : null;
        $runbook = $this->form->runbook_url ? trim((string) $this->form->runbook_url) : null;

        if ($this->editing_rule_id) {
            $rule = ServerFirewallRule::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->editing_rule_id)
                ->firstOrFail();
            $before = $rule->replicate();
            $rule->update([
                'name' => $this->form->name ? trim((string) $this->form->name) : null,
                'port' => $port,
                'protocol' => $this->form->protocol,
                'source' => strtolower(trim($this->form->source)) === 'any' ? 'any' : trim($this->form->source),
                'action' => $this->form->action,
                'enabled' => $this->form->enabled,
                'profile' => $profile,
                'tags' => $tags !== [] ? $tags : null,
                'runbook_url' => $runbook !== '' ? $runbook : null,
                'site_id' => $this->form->site_id ?: null,
            ]);
            $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_UPDATED, [
                'rule_id' => $rule->id,
            ], auth()->user());
            $rule->refresh();
            $this->syncRuleOnHostAfterMutation($firewall, $before, $rule);
            $this->flash_success = $this->flash_error
                ? __('Rule saved in the panel. Fix the UFW error below, then apply again if needed.')
                : __('Rule updated and synced to UFW.');
            $this->editing_rule_id = null;
            $this->form->resetForNew();
        } else {
            $rule = ServerFirewallRule::query()->create([
                'server_id' => $this->server->id,
                'name' => $this->form->name ? trim((string) $this->form->name) : null,
                'port' => $port,
                'protocol' => $this->form->protocol,
                'source' => strtolower(trim($this->form->source)) === 'any' ? 'any' : trim($this->form->source),
                'action' => $this->form->action,
                'enabled' => $this->form->enabled,
                'sort_order' => (int) ($this->server->firewallRules()->max('sort_order') ?? 0) + 1,
                'profile' => $profile,
                'tags' => $tags !== [] ? $tags : null,
                'runbook_url' => $runbook !== '' ? $runbook : null,
                'site_id' => $this->form->site_id ?: null,
            ]);
            $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_CREATED, [
                'rule_id' => $rule->id,
            ], auth()->user());
            if ($this->opsReady() && $rule->enabled) {
                try {
                    $this->server->refresh();
                    $out = $firewall->applyRule($this->server, $rule);
                    $this->flash_success = __('Rule saved and applied. :detail', [
                        'detail' => Str::limit(trim($out), 400),
                    ]);
                } catch (\Throwable $e) {
                    $this->flash_error = $e->getMessage();
                    $this->flash_success = __('Rule saved. Apply failed; use Apply or fix SSH.');
                }
            } else {
                $this->flash_success = __('Rule saved. Use “Apply firewall rules” to push enabled rules to the server.');
            }
            $this->form->resetForNew();
        }

        $this->flash_error = $this->flash_error ?? null;
    }

    /**
     * After an edit, remove the previous UFW entry (best effort) and apply the new definition when enabled.
     */
    private function syncRuleOnHostAfterMutation(
        ServerFirewallProvisioner $firewall,
        ServerFirewallRule $before,
        ServerFirewallRule $after
    ): void {
        if (! $this->opsReady()) {
            return;
        }

        $this->server->refresh();

        try {
            if ($before->enabled) {
                $firewall->removeFromHost($this->server, $before);
            }
            if ($after->enabled) {
                $firewall->applyRule($this->server, $after->fresh());
            }
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function toggleFirewallRuleEnabled(string $id, ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        $rule = ServerFirewallRule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        $wasEnabled = (bool) $rule->enabled;
        $snapshot = $rule->replicate();
        $rule->update(['enabled' => ! $wasEnabled]);
        $rule->refresh();

        if (! $this->opsReady()) {
            $this->flash_success = $rule->enabled
                ? __('Rule enabled. Apply firewall rules when SSH is ready to sync.')
                : __('Rule disabled. Re-enable and apply to restore on the server if needed.');

            return;
        }

        $this->server->refresh();
        $this->flash_error = null;

        try {
            if ($wasEnabled && ! $rule->enabled) {
                $snapshot->enabled = true;

                $this->flash_success = Str::limit(
                    trim($firewall->removeFromHost($this->server, $snapshot)),
                    900
                );
            } elseif (! $wasEnabled && $rule->enabled) {
                $this->flash_success = Str::limit(
                    trim($firewall->applyRule($this->server, $rule)),
                    900
                );
            } else {
                $this->flash_success = __('Preference saved.');
            }
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function moveFirewallRuleUp(string $id): void
    {
        $this->authorize('update', $this->server);
        $rules = $this->server->firewallRules()->orderBy('sort_order')->orderBy('id')->get();
        $idx = $rules->search(fn (ServerFirewallRule $r) => $r->id === $id);
        if ($idx === false || $idx < 1) {
            return;
        }
        $above = $rules[$idx - 1];
        $current = $rules[$idx];
        $tmp = $above->sort_order;
        $above->update(['sort_order' => $current->sort_order]);
        $current->update(['sort_order' => $tmp]);
        $this->flash_success = __('Order updated.');
    }

    public function moveFirewallRuleDown(string $id): void
    {
        $this->authorize('update', $this->server);
        $rules = $this->server->firewallRules()->orderBy('sort_order')->orderBy('id')->get();
        $idx = $rules->search(fn (ServerFirewallRule $r) => $r->id === $id);
        if ($idx === false || $idx >= $rules->count() - 1) {
            return;
        }
        $current = $rules[$idx];
        $below = $rules[$idx + 1];
        $tmp = $below->sort_order;
        $below->update(['sort_order' => $current->sort_order]);
        $current->update(['sort_order' => $tmp]);
        $this->flash_success = __('Order updated.');
    }

    public function deleteFirewallRule(string $id, ServerFirewallProvisioner $firewall, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $rule = ServerFirewallRule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        $remote = null;
        if ($this->opsReady() && $rule->enabled) {
            try {
                $this->server->refresh();
                $remote = trim($firewall->removeFromHost($this->server, $rule));
            } catch (\Throwable $e) {
                $remote = $e->getMessage();
            }
        }

        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_DELETED, [
            'rule_id' => $rule->id,
        ], auth()->user());

        $rule->delete();

        if ($this->editing_rule_id === $id) {
            $this->cancelEditRule();
        }

        $this->flash_success = __('Rule removed from the panel.');
        if ($remote !== null && $remote !== '') {
            $this->flash_success .= ' '.Str::limit($remote, 500);
        }
        $this->flash_error = null;
    }

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
            $this->flash_error = __('Select at least one rule.');

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
        $this->flash_success = __('Enabled :n rule(s) in the panel. Use “Apply firewall rules” to sync the host.', ['n' => $n]);
    }

    public function bulkDisableFirewallRules(ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $ids = array_values(array_unique(array_filter($this->firewall_bulk_ids)));
        if ($ids === []) {
            $this->flash_error = __('Select at least one rule.');

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
        $this->flash_success = __('Disabled :n rule(s) in the panel. Use “Apply firewall rules” to sync the host.', ['n' => $n]);
    }

    public function bulkDeleteFirewallRules(ServerFirewallProvisioner $firewall, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $ids = array_values(array_unique(array_filter($this->firewall_bulk_ids)));
        if ($ids === []) {
            $this->flash_error = __('Select at least one rule.');

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
        $this->flash_success = __('Removed :n rule(s).', ['n' => count($ruleIds)]);
    }

    public function applyFirewall(
        ServerFirewallProvisioner $firewall,
        ServerFirewallAuditLogger $audit,
        FirewallMaintenanceGate $maintenance,
        FirewallDualApprovalService $dualApproval,
        ServerFirewallApplyRecorder $recorder,
    ): void {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        $this->server->refresh();

        if ($reason = $maintenance->blockedReason($this->server)) {
            $this->flash_error = $reason;

            return;
        }
        $dual = $dualApproval->evaluateWebApply($this->server, auth()->user());
        if (! $dual['proceed']) {
            if ($dual['tone'] === 'success') {
                $this->flash_success = $dual['message'];
            } else {
                $this->flash_error = $dual['message'];
            }

            return;
        }

        $sshWarn = $firewall->sshAccessNotExplicitlyAllowed($this->server)
            ? ' '.__('Warning: no enabled Dply rule allows TCP :port from “any” — confirm SSH access before locking yourself out.', ['port' => $this->server->ssh_port ?: 22])
            : '';

        if ($firewall->sshAccessNotExplicitlyAllowed($this->server) && ! $this->firewall_ack_ssh_risk) {
            $this->flash_error = __('Check “I understand SSH may be unreachable” below, or add an allow rule for your SSH port, before applying.');

            return;
        }

        try {
            $out = $firewall->apply($this->server);
            $audit->record($this->server, ServerFirewallAuditEvent::EVENT_APPLY, [
                'output_excerpt' => Str::limit(trim($out), 1500),
            ], auth()->user());
            $recorder->recordSuccess($this->server, auth()->user(), null, $out, 'livewire');
            $this->firewall_ack_ssh_risk = false;
            $this->flash_success = Str::limit(trim($out).$sshWarn, 2200);
        } catch (\Throwable $e) {
            $recorder->recordFailure($this->server, auth()->user(), null, $e->getMessage(), 'livewire');
            $this->flash_error = $e->getMessage();
        }
    }

    public function refreshUfwStatus(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $this->ufw_status_text = $firewall->status($this->server);
            $this->flash_success = __('Refreshed UFW status from the server.');
        } catch (\Throwable $e) {
            $this->ufw_status_text = null;
            $this->flash_error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['firewallRules', 'organization', 'sites']);

        $org = $this->server->organization;
        $savedTemplates = $org
            ? FirewallRuleTemplate::query()
                ->where('organization_id', $org->id)
                ->where(function ($q) {
                    $q->whereNull('server_id')->orWhere('server_id', $this->server->id);
                })
                ->orderBy('name')
                ->get()
            : collect();

        $provisioner = app(ServerFirewallProvisioner::class);

        return view('livewire.servers.workspace-firewall', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'bundledTemplates' => config('server_firewall.bundled_templates', []),
            'policyPacks' => config('server_firewall.policy_packs', []),
            'savedTemplates' => $savedTemplates,
            'snapshots' => $this->server->firewallSnapshots()->limit(25)->get(),
            'auditEvents' => $this->server->firewallAuditEvents()->with('user')->limit(40)->get(),
            'applyLogs' => $this->server->firewallApplyLogs()->with(['user'])->limit(25)->get(),
            'sshNotCovered' => $provisioner->sshAccessNotExplicitlyAllowed($this->server),
        ]);
    }
}
