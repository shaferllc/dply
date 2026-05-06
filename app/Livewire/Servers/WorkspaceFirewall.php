<?php

namespace App\Livewire\Servers;

use App\Jobs\ApplyFirewallJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Forms\FirewallRuleForm;
use App\Livewire\Servers\Concerns\GuardsDisruptiveActions;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesFirewallWorkspaceAdvanced;
use App\Models\FirewallRuleTemplate;
use App\Models\Server;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceFirewall extends Component
{
    use ConfirmsActionWithModal;
    use EmitsPanelEvent;
    use GuardsDisruptiveActions;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesFirewallWorkspaceAdvanced;

    public FirewallRuleForm $form;

    public ?string $editing_rule_id = null;

    public ?string $ufw_status_text = null;

    protected ?string $lastUfwHostSyncError = null;

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
        $this->lastUfwHostSyncError = null;

        $port = in_array($this->form->protocol, ['icmp', 'ipv6-icmp'], true) ? null : $this->form->port;
        $tags = FirewallRuleForm::tagsStringToArray($this->form->tags);
        $profile = $this->form->profile ? trim((string) $this->form->profile) : null;
        $runbook = $this->form->runbook_url ? trim((string) $this->form->runbook_url) : null;
        $source = strtolower(trim($this->form->source)) === 'any' ? 'any' : trim($this->form->source);

        $duplicateRuleQuery = ServerFirewallRule::query()
            ->where('server_id', $this->server->id)
            ->where('port', $port)
            ->where('protocol', $this->form->protocol)
            ->where('source', $source)
            ->where('action', $this->form->action)
            ->where('site_id', $this->form->site_id ?: null);

        if ($this->editing_rule_id) {
            $duplicateRuleQuery->whereKeyNot($this->editing_rule_id);
        }

        if ($duplicateRuleQuery->exists()) {
            $this->addError('form.port', __('A matching firewall rule already exists on this server.'));

            return;
        }

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
                'source' => $source,
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
            if ($this->lastUfwHostSyncError) {
                $this->toastWarning(
                    __('Rule updated in the panel, but the host could not be synced: :err', ['err' => $this->lastUfwHostSyncError])
                );
            } else {
                $this->toastSuccess(__('Rule updated and synced to UFW.'));
            }
            $this->emitPanelEvent(
                __('Rule updated — apply to reconcile the host firewall'),
                array_values(array_filter([
                    sprintf('> Updated "%s" in the panel.', $rule->name ?: ($rule->action.' '.$rule->protocol.' '.($rule->port ?? '*'))),
                    sprintf('  %s %s/%s from %s', strtoupper($rule->action), $rule->port ?? '*', strtoupper($rule->protocol), $rule->source),
                    $this->lastUfwHostSyncError ? '> Host sync failed: '.\Illuminate\Support\Str::limit($this->lastUfwHostSyncError, 200) : '> Host sync attempted automatically.',
                ])),
            );
            $this->editing_rule_id = null;
            $this->form->resetForNew();
            $this->dispatch('close-modal', 'add-firewall-rule-modal');
        } else {
            $rule = ServerFirewallRule::query()->create([
                'server_id' => $this->server->id,
                'name' => $this->form->name ? trim((string) $this->form->name) : null,
                'port' => $port,
                'protocol' => $this->form->protocol,
                'source' => $source,
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
                    $this->toastSuccess(__('Rule saved and applied. :detail', [
                        'detail' => Str::limit(trim($out), 400),
                    ]));
                } catch (\Throwable $e) {
                    $this->lastUfwHostSyncError = $e->getMessage();
                    $this->toastWarning(
                        __('Rule saved. Apply failed: :msg. You can use “Apply firewall rules” or fix SSH.', ['msg' => $e->getMessage()])
                    );
                }
            } else {
                $this->toastSuccess(__('Rule saved. Use “Apply firewall rules” to push enabled rules to the server.'));
            }
            $this->emitPanelEvent(
                __('Rule added — apply to push to the server'),
                array_values(array_filter([
                    sprintf('> Added "%s" to the panel.', $rule->name ?: ($rule->action.' '.$rule->protocol.' '.($rule->port ?? '*'))),
                    sprintf('  %s %s/%s from %s', strtoupper($rule->action), $rule->port ?? '*', strtoupper($rule->protocol), $rule->source),
                    $rule->enabled
                        ? '> Rule is enabled. Click "Apply rules" to reconcile the host firewall.'
                        : '> Rule is disabled — Apply will skip it until you toggle it on.',
                    isset($this->lastUfwHostSyncError) && $this->lastUfwHostSyncError !== null
                        ? '> Inline host apply failed: '.\Illuminate\Support\Str::limit($this->lastUfwHostSyncError, 200)
                        : null,
                ])),
            );
            $this->form->resetForNew();
            $this->dispatch('close-modal', 'add-firewall-rule-modal');
        }
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
            $this->lastUfwHostSyncError = $e->getMessage();
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
            $this->toastSuccess($rule->enabled
                ? __('Rule enabled. Apply firewall rules when SSH is ready to sync.')
                : __('Rule disabled. Re-enable and apply to restore on the server if needed.'));

            return;
        }

        $this->server->refresh();

        try {
            if ($wasEnabled && ! $rule->enabled) {
                $snapshot->enabled = true;

                $this->toastSuccess(Str::limit(
                    trim($firewall->removeFromHost($this->server, $snapshot)),
                    900
                ));
            } elseif (! $wasEnabled && $rule->enabled) {
                $this->toastSuccess(Str::limit(
                    trim($firewall->applyRule($this->server, $rule)),
                    900
                ));
            } else {
                $this->toastSuccess(__('Preference saved.'));
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
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

        $removedMsg = __('Rule removed from the panel.');
        if ($remote !== null && $remote !== '') {
            $removedMsg .= ' '.Str::limit($remote, 500);
        }
        $this->emitPanelEvent(
            __('Rule removed — apply to push to the server'),
            array_values(array_filter([
                sprintf('> Removed "%s" from the panel.', $rule->name ?: ($rule->action.' '.$rule->protocol.' '.($rule->port ?? '*'))),
                $remote !== null && $remote !== '' ? '  ufw output: '.\Illuminate\Support\Str::limit(trim($remote), 200) : null,
                '> Click "Apply rules" to reconcile the host firewall.',
            ])),
        );
        $this->toastSuccess($removedMsg);
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

    /** A queued/running apply older than this is treated as stuck so the operator can re-dispatch. */
    public const APPLY_STALE_THRESHOLD_SECONDS = 300;

    public function applyFirewall(
        ServerFirewallProvisioner $firewall,
        ServerFirewallAuditLogger $audit,
        ServerFirewallApplyRecorder $recorder,
        bool $override = false,
    ): void {
        $this->authorize('update', $this->server);
        $this->server->refresh();
        if (! $this->disruptiveActionAllowed(__('Apply firewall rules'), $override)) {
            return;
        }

        if ($firewall->sshAccessNotExplicitlyAllowed($this->server) && ! $this->firewall_ack_ssh_risk) {
            $this->toastError(
                __('Check “I understand SSH may be unreachable” below, or add an allow rule for your SSH port, before applying.')
            );

            return;
        }

        if ($this->isApplyBusy()) {
            $this->toastError(__('A firewall apply is already in flight on this server. Wait for it to finish before starting another.'));

            return;
        }

        $runId = (string) Str::ulid();
        $meta = $this->server->fresh()->meta ?? [];
        $meta[config('server_firewall.meta_apply_run_id_key')] = $runId;
        $meta[config('server_firewall.meta_apply_status_key')] = 'queued';
        $meta[config('server_firewall.meta_apply_started_at_key')] = now()->toIso8601String();
        $meta[config('server_firewall.meta_apply_finished_at_key')] = null;
        $meta[config('server_firewall.meta_apply_error_key')] = null;
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        $this->firewall_ack_ssh_risk = false;
        ApplyFirewallJob::dispatch($this->server->id, $runId, Auth::id());

        $this->toastSuccess(__('Firewall apply queued — watch the banner for live output. You can leave this page; the job runs on the queue.'));
    }

    /**
     * True while a firewall apply is queued or running. Treats stale entries (older than the
     * threshold) as no-longer-in-flight so a dead worker doesn't permanently block re-dispatch.
     */
    protected function isApplyBusy(): bool
    {
        $meta = $this->server->fresh()->meta ?? [];
        $status = (string) data_get($meta, config('server_firewall.meta_apply_status_key'));

        if (! in_array($status, ['queued', 'running'], true)) {
            return false;
        }

        $startedAt = (string) data_get($meta, config('server_firewall.meta_apply_started_at_key'));
        if ($startedAt === '') {
            return true;
        }
        try {
            return ! Carbon::parse($startedAt)->lt(now()->subSeconds(self::APPLY_STALE_THRESHOLD_SECONDS));
        } catch (\Throwable) {
            return false;
        }
    }

    public function pollApplyStatus(): void
    {
        $this->server->refresh();
    }

    public function dismissApplyBanner(): void
    {
        $this->authorize('update', $this->server);

        $status = (string) data_get($this->server->fresh()->meta ?? [], config('server_firewall.meta_apply_status_key'));
        if (in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $meta = $this->server->fresh()->meta ?? [];
        unset(
            $meta[config('server_firewall.meta_apply_run_id_key')],
            $meta[config('server_firewall.meta_apply_status_key')],
            $meta[config('server_firewall.meta_apply_started_at_key')],
            $meta[config('server_firewall.meta_apply_finished_at_key')],
            $meta[config('server_firewall.meta_apply_error_key')],
        );
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();
    }

    /**
     * Streaming output buffer for the active (or most recent) apply run. Empty list when no
     * run is tracked or the cache TTL has lapsed.
     *
     * @return list<string>
     */
    public function getApplyOutputLinesProperty(): array
    {
        $runId = (string) data_get($this->server->meta ?? [], config('server_firewall.meta_apply_run_id_key'));
        if ($runId === '') {
            return [];
        }
        $payload = Cache::get((string) config('server_firewall.apply_output_cache_key_prefix', 'firewall_apply_output:').$runId);
        if (! is_array($payload)) {
            return [];
        }
        $lines = $payload['lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
    }

    /**
     * Split an SSH command-output blob into transcript lines, dropping empty lines and
     * capping the total so an enormous ufw status doesn't overwhelm the banner cache.
     *
     * @return list<string>
     */
    protected function splitOutputForBanner(string $blob, int $maxLines = 200): array
    {
        $lines = array_values(array_filter(
            array_map('rtrim', preg_split("/\r?\n/", trim($blob)) ?: []),
            static fn (string $l): bool => $l !== '',
        ));

        return count($lines) > $maxLines
            ? array_merge(array_slice($lines, 0, $maxLines), [sprintf('… (%d more lines truncated)', count($lines) - $maxLines)])
            : $lines;
    }

    public function refreshUfwStatus(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        try {
            $this->server->refresh();
            $this->ufw_status_text = $firewall->status($this->server);
            $this->emitPanelEvent(
                __('UFW status refreshed.'),
                array_merge(
                    ['> ufw status verbose against '.$this->server->getSshConnectionString().' …'],
                    $this->splitOutputForBanner((string) $this->ufw_status_text),
                ),
                'completed',
            );
            $this->toastSuccess(__('Refreshed UFW status — see the banner for the host output.'));
        } catch (\Throwable $e) {
            $this->ufw_status_text = null;
            $this->emitPanelEvent(
                __('UFW status fetch failed.'),
                [
                    '> ufw status verbose against '.$this->server->getSshConnectionString().' …',
                    '> ERROR: '.Str::limit(trim($e->getMessage()), 800),
                ],
                'failed',
            );
            $this->toastError($e->getMessage());
        }
    }

    public bool $ufw_diagnostics_modal_open = false;

    public ?string $ufw_diagnostics_text = null;

    public function runFirewallDiagnostics(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        try {
            $this->server->refresh();
            $this->ufw_diagnostics_text = $firewall->diagnostics($this->server);
            $this->emitPanelEvent(
                __('Firewall diagnostics complete.'),
                array_merge(
                    ['> ufw status verbose · numbered · ss -ltn · iptables -L INPUT against '.$this->server->getSshConnectionString().' …'],
                    $this->splitOutputForBanner((string) $this->ufw_diagnostics_text, 400),
                ),
                'completed',
            );
            $this->toastSuccess(__('Diagnostics complete — see the banner for the full output.'));
        } catch (\Throwable $e) {
            $this->emitPanelEvent(
                __('Firewall diagnostics failed.'),
                [
                    '> diagnostics against '.$this->server->getSshConnectionString().' …',
                    '> ERROR: '.Str::limit(trim($e->getMessage()), 800),
                ],
                'failed',
            );
            $this->toastError($e->getMessage());
        }
    }

    public function closeFirewallDiagnostics(): void
    {
        $this->ufw_diagnostics_modal_open = false;
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
            'savedTemplates' => $savedTemplates,
            'auditEvents' => $this->server->firewallAuditEvents()->with('user')->limit(40)->get(),
            'applyLogs' => $this->server->firewallApplyLogs()->with(['user'])->limit(25)->get(),
            'sshNotCovered' => $provisioner->sshAccessNotExplicitlyAllowed($this->server),
            'applyFirewallConfirmMessage' => $this->disruptiveConfirmMessage(__('Apply firewall rules')),
        ]);
    }
}
