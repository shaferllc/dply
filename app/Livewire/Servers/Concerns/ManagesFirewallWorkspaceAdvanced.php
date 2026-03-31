<?php

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ApplyServerFirewallJob;
use App\Models\FirewallRuleTemplate;
use App\Models\ServerFirewallSnapshot;
use App\Services\Integrations\FirewallWebhookDispatcher;
use App\Services\Servers\FirewallDriftAnalyzer;
use App\Services\Servers\FirewallRuleTemplateApplicator;
use App\Services\Servers\FirewallTerraformExporter;
use App\Services\Servers\ProviderFirewallSyncService;
use App\Services\Servers\ServerFirewallImportExport;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\Servers\ServerFirewallSnapshotService;

trait ManagesFirewallWorkspaceAdvanced
{
    public function updatedFirewallWorkspaceTab(string $value): void
    {
        if ($value === 'advanced') {
            $this->loadFirewallAdvancedContext(app(ProviderFirewallSyncService::class));
        }
    }

    /** @var 'rules'|'templates'|'advanced' */
    public string $firewall_workspace_tab = 'rules';

    /** @var list<string>|null */
    public ?array $firewall_preview_lines = null;

    /** @var array<string, mixed>|null */
    public ?array $firewall_drift = null;

    public string $firewall_import_text = '';

    public string $new_saved_template_name = '';

    public ?string $new_saved_template_description = null;

    /** org = organization-wide; server = this server only */
    public string $new_saved_template_scope = 'org';

    public ?string $snapshot_label_input = null;

    public int $schedule_apply_delay_minutes = 5;

    public ?string $provider_sync_blurb = null;

    public bool $firewall_ack_ssh_risk = false;

    public string $firewall_terraform_hcl = '';

    /** @var list<string> */
    public array $firewall_bulk_ids = [];

    public ?string $firewall_iptables_text = null;

    public function loadFirewallAdvancedContext(ProviderFirewallSyncService $provider): void
    {
        $this->provider_sync_blurb = $provider->describe($this->server);
    }

    public function previewFirewallCommands(): void
    {
        $this->authorize('update', $this->server);
        $this->server->refresh();
        $provisioner = app(ServerFirewallProvisioner::class);
        $this->firewall_preview_lines = $provisioner->previewApplyCommands($this->server);
        $this->flash_success = __('Preview updated.');
    }

    public function analyzeFirewallDrift(): void
    {
        $this->authorize('update', $this->server);
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $provisioner = app(ServerFirewallProvisioner::class);
            $status = $provisioner->status($this->server);
            $this->ufw_status_text = $status;
            $enabled = $this->server->firewallRules()->where('enabled', true)->orderBy('sort_order')->get();
            $this->firewall_drift = app(FirewallDriftAnalyzer::class)->analyze($this->server, $status, $enabled);
            $org = $this->server->organization;
            if ($org
                && ! empty($org->mergedFirewallSettings()['notify_drift_webhook'])
                && ! ($this->firewall_drift['in_sync'] ?? true)) {
                app(FirewallWebhookDispatcher::class)->dispatch(
                    $org,
                    $this->server,
                    'firewall_drift_detected',
                    __('UFW drift detected — review the firewall workspace.')
                );
            }
            $this->flash_success = __('Drift analysis updated.');
        } catch (\Throwable $e) {
            $this->firewall_drift = null;
            $this->flash_error = $e->getMessage();
        }
    }

    public function applyBundledFirewallTemplate(string $key): void
    {
        $this->authorize('update', $this->server);
        try {
            $n = app(FirewallRuleTemplateApplicator::class)->applyBundled($this->server, $key, auth()->user());
            $this->flash_success = __('Added :n rule(s) from the bundle.', ['n' => $n]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
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
            $this->flash_success = __('Added :n rule(s) from template “:name”.', ['n' => $n, 'name' => $tpl->name]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
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
        $this->flash_success = __('Template saved.');
    }

    public function exportFirewallJson(): void
    {
        $this->authorize('update', $this->server);
        $this->firewall_import_text = trim(app(ServerFirewallImportExport::class)->exportJson($this->server));
        $this->flash_success = __('JSON ready — copy from the field below.');
    }

    public function exportFirewallTerraform(): void
    {
        $this->authorize('update', $this->server);
        $this->firewall_terraform_hcl = app(FirewallTerraformExporter::class)->toHcl($this->server);
        $this->flash_success = __('Terraform-style snippet generated below (documentation only).');
    }

    public function refreshFirewallIptablesSnapshot(): void
    {
        $this->authorize('update', $this->server);
        $this->flash_error = null;
        if (! config('server_firewall.danger_zone.iptables_counters_enabled', false)) {
            $this->firewall_iptables_text = null;
            $this->flash_error = __('Enable SERVER_FIREWALL_IPTABLES_COUNTERS or server_firewall.danger_zone.iptables_counters_enabled to use this (read-only host introspection).');

            return;
        }
        try {
            $this->server->refresh();
            $this->firewall_iptables_text = app(ServerFirewallProvisioner::class)
                ->iptablesCountersSnapshot($this->server);
            $this->flash_success = __('Refreshed iptables snapshot (first rows only).');
        } catch (\Throwable $e) {
            $this->firewall_iptables_text = null;
            $this->flash_error = $e->getMessage();
        }
    }

    /**
     * Merge multiple bundled templates in order (see config server_firewall.policy_packs).
     */
    public function applyPolicyPack(string $key): void
    {
        $this->authorize('update', $this->server);
        $packs = config('server_firewall.policy_packs', []);
        if (! isset($packs[$key]) || ! is_array($packs[$key]['bundled_templates'] ?? null)) {
            $this->flash_error = __('Unknown policy pack.');

            return;
        }
        $keys = $packs[$key]['bundled_templates'];
        if (! is_array($keys)) {
            return;
        }
        $applicator = app(FirewallRuleTemplateApplicator::class);
        $total = 0;
        try {
            foreach ($keys as $bundledKey) {
                if (! is_string($bundledKey)) {
                    continue;
                }
                $total += $applicator->applyBundled($this->server, $bundledKey, auth()->user());
            }
            $this->flash_success = __('Added :n rule(s) from policy pack “:pack”.', [
                'n' => $total,
                'pack' => $packs[$key]['label'] ?? $key,
            ]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function importFirewallJson(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['firewall_import_text' => 'required|string|max:512000']);
        try {
            $n = app(ServerFirewallImportExport::class)->importJson($this->server, $this->firewall_import_text, auth()->user(), true);
            $this->flash_success = __('Imported :n rule(s).', ['n' => $n]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function createFirewallSnapshot(): void
    {
        $this->authorize('update', $this->server);
        $label = $this->snapshot_label_input ? trim($this->snapshot_label_input) : null;
        app(ServerFirewallSnapshotService::class)->create($this->server, auth()->user(), $label);
        $this->snapshot_label_input = null;
        $this->flash_success = __('Snapshot saved.');
    }

    public function restoreFirewallSnapshot(string $id): void
    {
        $this->authorize('update', $this->server);
        $snap = ServerFirewallSnapshot::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();
        app(ServerFirewallSnapshotService::class)->restore($this->server, $snap, auth()->user());
        $this->flash_success = __('Restored snapshot.');
    }

    public function scheduleDelayedFirewallApply(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['schedule_apply_delay_minutes' => 'required|integer|min:1|max:1440']);
        ApplyServerFirewallJob::dispatch($this->server->id, auth()->id())
            ->delay(now()->addMinutes($this->schedule_apply_delay_minutes));
        $this->flash_success = __('Queued firewall apply in :m minute(s).', ['m' => $this->schedule_apply_delay_minutes]);
    }
}
