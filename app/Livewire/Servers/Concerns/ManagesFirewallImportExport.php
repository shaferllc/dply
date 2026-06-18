<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesFirewallImportExport
{
    /**
     * Open state + parsed-rule list for the "Import from host" preview modal. Each row carries
     * a stable index used as the checkbox value so we can reconcile selections back to the
     * parsed rule on submit. Rows that the parser couldn't decode are flagged `importable=false`
     * and rendered read-only.
     *
     * @var list<array{
     *     index: int,
     *     action: ?string,
     *     port: ?int,
     *     protocol: ?string,
     *     source: ?string,
     *     raw: string,
     *     already_in_panel: bool,
     *     importable: bool,
     * }>
     */
    public array $import_host_rules = [];

    /**
     * Indexes of `import_host_rules` the operator has ticked for import.
     *
     * @var list<int>
     */
    public array $import_host_selected = [];

    /**
     * Pull the host's user-added UFW rules and stage them in the import-preview modal. Rules
     * that already match a panel entry are flagged so the operator only ticks net-new ones by
     * default. Existing panel rules are never modified by this action — the diff is one-way
     * (host → panel), confirmed in {@see confirmImportHostRules()}.
     */
    public function previewImportHostRules(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        if (! $this->opsReady()) {
            $this->toastError(__('Server must be ready with SSH before importing rules.'));

            return;
        }

        try {
            $this->server->refresh();
            $hostRules = $firewall->importableRulesFromHost($this->server);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $existingKeys = $this->server->firewallRules
            ->mapWithKeys(fn (ServerFirewallRule $r) => [
                $this->ruleMatchKey($r->port, $r->protocol, $r->action, $r->source) => true,
            ])
            ->all();

        $rows = [];
        $defaultSelected = [];
        foreach ($hostRules as $i => $r) {
            $importable = $r['action'] !== null && ($r['protocol'] !== null);
            $alreadyIn = false;
            if ($importable) {
                $alreadyIn = isset($existingKeys[$this->ruleMatchKey(
                    $r['port'],
                    $r['protocol'],
                    $r['action'],
                    $r['source']
                )]);
            }
            $rows[] = [
                'index' => $i,
                'action' => $r['action'],
                'port' => $r['port'],
                'protocol' => $r['protocol'],
                'source' => $r['source'],
                'raw' => $r['raw'],
                'already_in_panel' => $alreadyIn,
                'importable' => $importable,
            ];
            if ($importable && ! $alreadyIn) {
                $defaultSelected[] = $i;
            }
        }

        $this->import_host_rules = $rows;
        $this->import_host_selected = $defaultSelected;
        $this->dispatch('open-modal', 'import-host-firewall-rules-modal');

        if ($rows === []) {
            $this->toastSuccess(__('No user-added UFW rules found on the host.'));
        }
    }

    public function closeImportHostRulesModal(): void
    {
        $this->dispatch('close-modal', 'import-host-firewall-rules-modal');
        $this->import_host_rules = [];
        $this->import_host_selected = [];
    }

    /**
     * Persist the operator-selected host rules into the panel. Rules that already exist in the
     * panel (matched by port/proto/action/source) are skipped silently to make re-import safe.
     */
    public function confirmImportHostRules(ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        $selected = array_values(array_unique(array_map('intval', $this->import_host_selected)));
        $byIndex = [];
        foreach ($this->import_host_rules as $row) {
            $byIndex[(int) $row['index']] = $row;
        }

        $created = 0;
        $skippedAsDuplicate = 0;
        $createdRuleIds = [];

        $nextSort = (int) ($this->server->firewallRules()->max('sort_order') ?? 0);

        foreach ($selected as $idx) {
            $row = $byIndex[$idx] ?? null;
            if ($row === null || ! $row['importable']) {
                continue;
            }
            $port = $row['port'];
            $protocol = (string) $row['protocol'];
            $action = (string) $row['action'];
            $source = (string) ($row['source'] ?? 'any');
            $matchKey = $this->ruleMatchKey($port, $protocol, $action, $source);

            $alreadyThere = $this->server->firewallRules()
                ->where('port', $port)
                ->where('protocol', $protocol)
                ->where('action', $action)
                ->where('source', $source)
                ->exists();
            if ($alreadyThere) {
                $skippedAsDuplicate++;

                continue;
            }

            $nextSort++;
            $rule = ServerFirewallRule::query()->create([
                'server_id' => $this->server->id,
                'name' => __('Imported from host'),
                'port' => $port,
                'protocol' => $protocol,
                'source' => $source,
                'action' => $action,
                'enabled' => true,
                'sort_order' => $nextSort,
                'tags' => ['imported'],
            ]);
            $created++;
            $createdRuleIds[] = $rule->id;
        }

        if ($created > 0) {
            $audit->record($this->server, ServerFirewallAuditEvent::EVENT_IMPORT, [
                'rule_ids' => $createdRuleIds,
                'count' => $created,
                'source' => 'ufw_show_added',
            ], auth()->user());
        }

        $this->dispatch('close-modal', 'import-host-firewall-rules-modal');
        $this->import_host_rules = [];
        $this->import_host_selected = [];

        if ($created === 0 && $skippedAsDuplicate === 0) {
            $this->toastError(__('No rules were selected to import.'));

            return;
        }

        $msg = __(':n rule(s) imported into the panel.', ['n' => $created]);
        if ($skippedAsDuplicate > 0) {
            $msg .= ' '.__(':n already existed and were skipped.', ['n' => $skippedAsDuplicate]);
        }
        $msg .= ' '.__('They are not yet pushed back to the host — click "Apply rules" to reconcile.');
        $this->toastSuccess($msg);
    }

    /**
     * Create (or re-enable) a panel rule that explicitly allows TCP on the server's configured
     * SSH port from anywhere. Idempotent — already-correct setups just get a toast saying so.
     * Use this when the "no SSH allow rule" warning fires, or before tightening UFW.
     */
    public function ensureSshAllowRule(
        ServerFirewallProvisioner $firewall,
        ServerFirewallAuditLogger $audit,
    ): void {
        $this->authorize('update', $this->server);
        $this->server->refresh();

        $sshPort = (int) ($this->server->ssh_port ?: 22);

        $candidate = $this->server->firewallRules()
            ->where('protocol', 'tcp')
            ->where('action', 'allow')
            ->where('port', $sshPort)
            ->where(function ($q): void {
                $q->where('source', 'any')
                    ->orWhere('source', '0.0.0.0/0')
                    ->orWhere('source', '::/0');
            })
            ->orderByDesc('enabled')
            ->first();

        if ($candidate && $candidate->enabled) {
            $this->toastSuccess(__('SSH is already covered — TCP :port is allowed from any.', ['port' => $sshPort]));

            return;
        }

        if ($candidate && ! $candidate->enabled) {
            $candidate->update(['enabled' => true]);
            $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_UPDATED, [
                'rule_id' => $candidate->id,
                'change' => 'ensure_ssh_allow_enabled',
            ], auth()->user());
            if ($this->opsReady()) {
                try {
                    $firewall->applyRule($this->server, $candidate->fresh());
                } catch (\Throwable $e) {
                    $this->toastWarning(__('Re-enabled the SSH rule, but pushing it to UFW failed: :err', ['err' => $e->getMessage()]));

                    return;
                }
            }
            $this->toastSuccess(__('Re-enabled the existing SSH allow rule for TCP :port.', ['port' => $sshPort]));

            return;
        }

        $rule = ServerFirewallRule::query()->create([
            'server_id' => $this->server->id,
            'name' => __('SSH (auto)'),
            'port' => $sshPort,
            'protocol' => 'tcp',
            'source' => 'any',
            'action' => 'allow',
            'enabled' => true,
            'sort_order' => (int) ($this->server->firewallRules()->max('sort_order') ?? 0) + 1,
            'tags' => ['ssh', 'safety-rail'],
        ]);
        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_CREATED, [
            'rule_id' => $rule->id,
            'reason' => 'ensure_ssh_allow',
        ], auth()->user());

        if ($this->opsReady()) {
            try {
                $firewall->applyRule($this->server, $rule->fresh());
            } catch (\Throwable $e) {
                $this->toastWarning(__('Created the SSH allow rule, but pushing it to UFW failed: :err', ['err' => $e->getMessage()]));

                return;
            }
        }

        $this->toastSuccess(__('Added an allow rule for TCP :port from any. Apply when ready to push to UFW.', ['port' => $sshPort]));
    }

    /**
     * Stream the current rule set as a JSON file shaped exactly like a saved-template payload.
     * That round-trip makes the export usable as both "audit dump" and "bootstrap another
     * server" — drop the file into a new server's template upload (or feed it to the template
     * applicator directly) and you get the same rules without retyping.
     */
    public function exportFirewallRulesJson(): StreamedResponse
    {
        $this->authorize('update', $this->server);
        $this->server->load(['firewallRules' => fn ($q) => $q->orderBy('sort_order')]);

        $payload = [
            'name' => sprintf('%s firewall rules', $this->server->name),
            'description' => sprintf('Exported from Dply on %s', now()->toDateString()),
            'rules' => $this->server->firewallRules->map(fn (ServerFirewallRule $r): array => [
                'name' => $r->name,
                'port' => $r->port,
                'protocol' => $r->protocol,
                'source' => $r->source,
                'iface' => $r->iface,
                'iface_direction' => $r->iface_direction,
                'action' => $r->action,
                'enabled' => (bool) $r->enabled,
                'sort_order' => (int) $r->sort_order,
                'profile' => $r->profile,
                'app_profile' => $r->app_profile,
                'tags' => $r->tags,
                'runbook_url' => $r->runbook_url,
                // site_id intentionally omitted — IDs don't carry across servers.
            ])->values()->all(),
        ];

        $filename = sprintf('firewall-rules-%s-%s.json', Str::slug($this->server->name), now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * CSV export — operator-friendly columns, no app_profile/iface_direction (which CSV-flatten
     * poorly). Use the JSON export for round-trip imports; CSV is for spreadsheet audits.
     */
    public function exportFirewallRulesCsv(): StreamedResponse
    {
        $this->authorize('update', $this->server);
        $this->server->load(['firewallRules' => fn ($q) => $q->orderBy('sort_order')]);

        $rules = $this->server->firewallRules;
        $filename = sprintf('firewall-rules-%s-%s.csv', Str::slug($this->server->name), now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($rules): void {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, ['name', 'action', 'port', 'protocol', 'source', 'iface', 'iface_direction', 'app_profile', 'profile', 'enabled', 'sort_order', 'tags', 'runbook_url']);
            foreach ($rules as $r) {
                fputcsv($fh, [
                    (string) ($r->name ?? ''),
                    (string) $r->action,
                    $r->port === null ? '' : (string) $r->port,
                    (string) $r->protocol,
                    (string) $r->source,
                    (string) ($r->iface ?? ''),
                    (string) ($r->iface_direction ?? ''),
                    (string) ($r->app_profile ?? ''),
                    (string) ($r->profile ?? ''),
                    $r->enabled ? '1' : '0',
                    (string) (int) $r->sort_order,
                    is_array($r->tags) ? implode(',', $r->tags) : '',
                    (string) ($r->runbook_url ?? ''),
                ]);
            }
            fclose($fh);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
