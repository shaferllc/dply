<?php

namespace App\Livewire\Servers;

use App\Jobs\ApplyFirewallJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Forms\FirewallRuleForm;
use App\Livewire\Servers\Concerns\GuardsDisruptiveActions;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesFirewallNotifications;
use App\Livewire\Servers\Concerns\ManagesFirewallWorkspaceAdvanced;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\FirewallRuleTemplate;
use App\Models\Server;
use App\Models\ServerFirewallApplyLog;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Models\User;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\SshConnection;
use App\Support\Servers\FirewallWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceFirewall extends Component
{
    use ConfirmsActionWithModal;
    use CreatesNotificationChannelInline;
    use EmitsPanelEvent;
    use GuardsDisruptiveActions;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesFirewallNotifications;
    use ManagesFirewallWorkspaceAdvanced;
    use RendersWorkspacePlaceholder;

    public FirewallRuleForm $form;

    public ?string $editing_rule_id = null;

    public ?string $ufw_status_text = null;

    protected ?string $lastUfwHostSyncError = null;

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

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->form->resetForNew();
    }

    public function setFirewallWorkspaceTab(string $tab): void
    {
        $allowed = ['rules', 'templates', 'activity', 'notifications'];
        $this->firewall_workspace_tab = in_array($tab, $allowed, true) ? $tab : 'rules';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->firewall_workspace_tab = 'notifications';
        $this->notif_channel_id = $channelId;
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
        $appProfile = trim((string) ($this->form->app_profile ?? ''));
        $appProfile = $appProfile !== '' ? $appProfile : null;
        if ($appProfile !== null) {
            // App-profile rules let UFW resolve the port/protocol from /etc/ufw/applications.d.
            // Clearing `port` here mirrors the apply path and keeps the duplicate-check below
            // tight (two rules with the same app profile and source ARE duplicates).
            $port = null;
        }
        $iface = trim((string) ($this->form->iface ?? ''));
        $iface = $iface !== '' ? $iface : null;
        $ifaceDirection = $iface === null ? null : ($this->form->iface_direction ?: 'in');
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
            ->where('app_profile', $appProfile)
            ->where('iface', $iface)
            ->where('iface_direction', $ifaceDirection)
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
                'iface' => $iface,
                'iface_direction' => $ifaceDirection,
                'action' => $this->form->action,
                'enabled' => $this->form->enabled,
                'profile' => $profile,
                'app_profile' => $appProfile,
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
                    $this->lastUfwHostSyncError ? '> Host sync failed: '.Str::limit($this->lastUfwHostSyncError, 200) : '> Host sync attempted automatically.',
                ])),
            );
            $this->dispatchFirewallNotification('updated', [$this->firewallRuleLabel($rule)], [
                'rule_id' => $rule->id,
                'port' => $rule->port,
                'protocol' => $rule->protocol,
                'source' => $rule->source,
                'action' => $rule->action,
            ]);
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
                'iface' => $iface,
                'iface_direction' => $ifaceDirection,
                'action' => $this->form->action,
                'enabled' => $this->form->enabled,
                'sort_order' => (int) ($this->server->firewallRules()->max('sort_order') ?? 0) + 1,
                'profile' => $profile,
                'app_profile' => $appProfile,
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
                        ? '> Inline host apply failed: '.Str::limit($this->lastUfwHostSyncError, 200)
                        : null,
                ])),
            );
            $this->dispatchFirewallNotification('created', [$this->firewallRuleLabel($rule)], [
                'rule_id' => $rule->id,
                'port' => $rule->port,
                'protocol' => $rule->protocol,
                'source' => $rule->source,
                'action' => $rule->action,
                'enabled' => (bool) $rule->enabled,
            ]);
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

    public function toggleFirewallRuleEnabled(string $id, ServerFirewallProvisioner $firewall, ServerFirewallAuditLogger $audit): void
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

        $audit->record(
            $this->server,
            $rule->enabled ? 'rule_enabled' : 'rule_disabled',
            [
                'rule_id' => (string) $rule->id,
                'port' => $rule->port,
                'protocol' => $rule->protocol,
                'source' => $rule->source,
                'action' => $rule->action,
                'name' => $rule->name,
                'was_enabled' => $wasEnabled,
                'now_enabled' => (bool) $rule->enabled,
            ],
            auth()->user(),
        );

        $this->dispatchFirewallNotification('updated', [$this->firewallRuleLabel($rule)], [
            'rule_id' => (string) $rule->id,
            'change' => $rule->enabled ? 'enabled' : 'disabled',
        ]);

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
                $remote !== null && $remote !== '' ? '  ufw output: '.Str::limit(trim($remote), 200) : null,
                '> Click "Apply rules" to reconcile the host firewall.',
            ])),
        );
        $this->dispatchFirewallNotification('deleted', [$this->firewallRuleLabel($rule)], [
            'rule_id' => $id,
        ]);
        $this->toastSuccess($removedMsg);
    }

    /**
     * Swap a rule's sort_order with its immediate neighbour. UFW evaluates rules in numeric
     * order (first match wins for allow/deny), so the operator-facing knob matches the actual
     * apply behaviour. No host-side change — order takes effect on the next Apply.
     */
    public function moveFirewallRule(string $id, string $direction, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        if (! in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $rules = $this->server->firewallRules()->orderBy('sort_order')->orderBy('id')->get();
        $index = $rules->search(fn (ServerFirewallRule $r) => (string) $r->id === $id);
        if ($index === false) {
            return;
        }

        $neighbourIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($neighbourIndex < 0 || $neighbourIndex >= $rules->count()) {
            return;
        }

        $current = $rules[$index];
        $neighbour = $rules[$neighbourIndex];

        DB::transaction(function () use ($current, $neighbour): void {
            $a = (int) $current->sort_order;
            $b = (int) $neighbour->sort_order;

            // Equal sort_order values would no-op; bump the neighbour down so the swap still
            // produces a visible reorder for legacy rows that were inserted with the same key.
            if ($a === $b) {
                $b = $a + 1;
            }

            $current->update(['sort_order' => $b]);
            $neighbour->update(['sort_order' => $a]);
        });

        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_UPDATED, [
            'rule_id' => $current->id,
            'change' => 'reorder',
            'direction' => $direction,
        ], auth()->user());
    }

    /**
     * Bound to the three default-policy selects on the Rules tab. Persists onto server.meta so
     * the next Apply will emit `ufw default <policy> <chain>` before per-rule fragments. Empty
     * string means "leave UFW's default in place" — that key is removed from meta entirely so
     * we don't accidentally pin a default someone deselected.
     */
    public function setFirewallDefaultPolicy(string $chain, string $policy, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        $keyMap = [
            'incoming' => (string) config('server_firewall.meta_default_incoming_key', 'firewall_default_incoming'),
            'outgoing' => (string) config('server_firewall.meta_default_outgoing_key', 'firewall_default_outgoing'),
            'routed' => (string) config('server_firewall.meta_default_routed_key', 'firewall_default_routed'),
        ];
        if (! isset($keyMap[$chain])) {
            return;
        }

        $allowed = (array) config('server_firewall.default_policies', ['allow', 'deny', 'reject']);
        $policy = strtolower(trim($policy));
        if ($policy !== '' && ! in_array($policy, $allowed, true)) {
            return;
        }

        $meta = $this->server->fresh()->meta ?? [];
        $previous = $meta[$keyMap[$chain]] ?? null;
        if ($policy === '') {
            unset($meta[$keyMap[$chain]]);
        } else {
            $meta[$keyMap[$chain]] = $policy;
        }
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_UPDATED, [
            'change' => 'default_policy',
            'chain' => $chain,
            'from' => $previous,
            'to' => $policy === '' ? null : $policy,
        ], auth()->user());

        $this->toastSuccess($policy === ''
            ? __('Default :chain policy cleared — UFW default applies on next Apply.', ['chain' => $chain])
            : __('Default :chain policy set to :policy — pushed on next Apply.', ['chain' => $chain, 'policy' => $policy]));
    }

    /**
     * Persist the desired UFW logging level onto server.meta. Empty string clears the override
     * so the next Apply leaves the host's current logging setting alone.
     */
    public function setFirewallLoggingLevel(string $level, ServerFirewallAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        $key = (string) config('server_firewall.meta_logging_level_key', 'firewall_logging_level');
        $allowed = (array) config('server_firewall.logging_levels', ['off', 'low', 'medium', 'high', 'full']);
        $level = strtolower(trim($level));
        if ($level !== '' && ! in_array($level, $allowed, true)) {
            return;
        }

        $meta = $this->server->fresh()->meta ?? [];
        $previous = $meta[$key] ?? null;
        if ($level === '') {
            unset($meta[$key]);
        } else {
            $meta[$key] = $level;
        }
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        $audit->record($this->server, ServerFirewallAuditEvent::EVENT_RULE_UPDATED, [
            'change' => 'logging_level',
            'from' => $previous,
            'to' => $level === '' ? null : $level,
        ], auth()->user());

        $this->toastSuccess($level === ''
            ? __('UFW logging override cleared — apply will leave the host setting alone.')
            : __('UFW logging level set to :level — pushed on next Apply.', ['level' => $level]));
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

    /**
     * Dry-run preview state. {@see previewApplyFirewall()} fills $apply_preview_lines with the
     * exact `ufw <fragment>` commands the next apply will run, in the same order the provisioner
     * emits them, and flips the modal open. {@see applyFirewall()} is reached only after the
     * operator confirms.
     *
     * @var list<string>
     */
    public array $apply_preview_lines = [];

    public bool $apply_preview_open = false;

    /**
     * Build the ordered list of UFW commands the upcoming apply will run, in the same order as
     * {@see ServerFirewallProvisioner::apply()}: defaults → SSH safety rail → per-rule fragments
     * → `--force enable`. Then open the preview modal.
     */
    public function previewApplyFirewall(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        $this->server->refresh();

        $lines = [];

        foreach ($firewall->defaultPoliciesFromMeta($this->server) as $chain => $policy) {
            $lines[] = sprintf('ufw default %s %s', $policy, $chain);
        }

        $loggingLevel = $firewall->loggingLevelFromMeta($this->server);
        if ($loggingLevel !== null) {
            $lines[] = sprintf('ufw logging %s', $loggingLevel);
        }

        $sshPort = (int) ($this->server->ssh_port ?: 22);
        $lines[] = sprintf("ufw allow %d/tcp comment 'Dply: keep SSH reachable'", $sshPort);

        $rules = $this->server->firewallRules()->where('enabled', true)->orderBy('sort_order')->get();
        foreach ($rules as $rule) {
            try {
                $fragment = $firewall->ufwRuleFragment($rule);
            } catch (\Throwable $e) {
                $fragment = '# skipped: '.$e->getMessage();
            }
            $lines[] = 'ufw '.$fragment.($rule->name ? '   # '.$rule->name : '');
        }

        $lines[] = 'ufw --force enable';

        $this->apply_preview_lines = $lines;
        $this->apply_preview_open = true;
    }

    public function closeApplyPreview(): void
    {
        $this->apply_preview_open = false;
        $this->apply_preview_lines = [];
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
        $this->apply_preview_open = false;
        $this->apply_preview_lines = [];
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

    /**
     * Re-run just the listening-ports portion of the inventory probe (`ss -lntpH`) and stamp
     * the result onto `meta.manage_listening_ports` so the table on the Rules tab refreshes.
     * Tries root first then falls back to the deploy user, mirroring the inventory probe.
     */
    public function refreshListeningPorts(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->opsReady()) {
            $this->toastError(__('Server must be ready with SSH before refreshing listening ports.'));

            return;
        }

        $this->server->refresh();

        $command = '/bin/sh -c '.escapeshellarg(
            '(sudo -n ss -lntpH 2>/dev/null || ss -lntpH 2>/dev/null) | head -n 60'
        );

        $deploy = trim((string) $this->server->ssh_user) ?: 'root';
        $candidates = [];
        if ((bool) config('server_settings.inventory_use_root_ssh', true) && $deploy !== 'root') {
            $candidates[] = 'root';
            if ((bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true)) {
                $candidates[] = $deploy;
            }
        } else {
            $candidates[] = $deploy;
        }

        $out = null;
        $lastError = null;
        foreach ($candidates as $loginUser) {
            try {
                $ssh = new SshConnection($this->server, $loginUser);
                $out = trim($ssh->exec($command, 30));
                $ssh->disconnect();
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($out === null) {
            $this->toastError($lastError !== null ? $lastError->getMessage() : __('SSH connection failed.'));

            return;
        }

        if (strlen($out) > 16384) {
            $out = substr($out, 0, 16384)."\n[dply] truncated";
        }

        $meta = $this->server->meta ?? [];
        if ($out !== '') {
            $meta['manage_listening_ports'] = $out;
        } else {
            unset($meta['manage_listening_ports']);
        }
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();

        $this->toastSuccess(__('Listening ports refreshed.'));
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

    public function render(): View
    {
        // Backwards compatibility: the History and Audit tabs were merged into a single
        // Activity timeline. Snap stale tab values forward so deep links still land somewhere.
        if (in_array($this->firewall_workspace_tab, ['history', 'audit'], true)) {
            $this->firewall_workspace_tab = 'activity';
        }

        $allowedTabs = ['rules', 'templates', 'activity', 'notifications'];
        if (! in_array($this->firewall_workspace_tab, $allowedTabs, true)) {
            $this->firewall_workspace_tab = 'rules';
        }

        $tab = $this->firewall_workspace_tab;
        $needsRules = $tab === 'rules';
        $needsTemplates = $tab === 'templates';
        $needsActivity = $tab === 'activity';
        $needsNotifications = $tab === 'notifications';

        $this->server->loadMissing(['organization']);

        if ($needsRules || $needsTemplates || $this->apply_preview_open) {
            $this->server->loadMissing(['firewallRules', 'sites']);
        }

        $provisioner = app(ServerFirewallProvisioner::class);

        $savedTemplates = collect();
        $bundledTemplates = [];
        $bundledAppliedMap = [];
        $activityItems = [];

        if ($needsTemplates) {
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

            $bundledTemplates = config('server_firewall.bundled_templates', []);

            $appliedKeys = $this->server->firewallRules
                ->mapWithKeys(fn ($r) => [$this->ruleMatchKey($r->port, $r->protocol, $r->action, $r->source) => true])
                ->all();

            $sshPort = (int) ($this->server->ssh_port ?: 22);

            foreach ($bundledTemplates as $key => $bundle) {
                $rules = $bundle['rules'] ?? [];
                if ($rules === []) {
                    $bundledAppliedMap[$key] = [
                        'state' => 'none',
                        'present_count' => 0,
                        'total' => 0,
                        'removable_count' => 0,
                        'has_ssh' => false,
                        'chips' => [],
                    ];

                    continue;
                }

                $chips = [];
                $presentCount = 0;
                $removableCount = 0;
                $hasSsh = false;

                foreach ($rules as $r) {
                    $rPort = isset($r['port']) ? (is_numeric($r['port']) ? (int) $r['port'] : null) : null;
                    $rProto = strtolower((string) ($r['protocol'] ?? 'tcp'));
                    $rAction = strtolower((string) ($r['action'] ?? 'allow'));
                    $rSource = strtolower(trim((string) ($r['source'] ?? 'any')));
                    $present = isset($appliedKeys[$this->ruleMatchKey($r['port'] ?? null, $r['protocol'] ?? null, $r['action'] ?? null, $r['source'] ?? null)]);
                    // An SSH allow rule on the server's configured SSH port from
                    // any source is the management lifeline — never auto-removable.
                    $isSsh = $rAction === 'allow'
                        && $rProto === 'tcp'
                        && $rPort === $sshPort
                        && in_array($rSource, ['any', '0.0.0.0/0', '::/0'], true);

                    if ($present) {
                        $presentCount++;
                    }
                    if ($present && ! $isSsh) {
                        $removableCount++;
                    }
                    if ($isSsh) {
                        $hasSsh = true;
                    }

                    $label = $rPort === null
                        ? $rProto
                        : $rPort.'/'.$rProto;
                    if ($rSource !== 'any' && $rSource !== '') {
                        $label .= ' ← '.($r['source'] ?? '');
                    }

                    $chips[] = [
                        'label' => $label,
                        'name' => (string) ($r['name'] ?? ''),
                        'present' => $present,
                        'is_ssh' => $isSsh,
                    ];
                }

                $total = count($rules);
                $state = $presentCount === 0 ? 'none' : ($presentCount >= $total ? 'all' : 'partial');

                $bundledAppliedMap[$key] = [
                    'state' => $state,
                    'present_count' => $presentCount,
                    'total' => $total,
                    'removable_count' => $removableCount,
                    'has_ssh' => $hasSsh,
                    'chips' => $chips,
                ];
            }
        }

        if ($needsActivity) {
            $activityItems = $this->buildActivityItems();
        }

        $sshNotCovered = false;
        $defaultPolicies = [];
        $loggingLevel = null;

        if ($needsRules || $this->apply_preview_open) {
            $sshNotCovered = $provisioner->sshAccessNotExplicitlyAllowed($this->server);
            $defaultPolicies = $provisioner->defaultPoliciesFromMeta($this->server);
            $loggingLevel = $provisioner->loggingLevelFromMeta($this->server);
        }

        return view('livewire.servers.workspace-firewall', array_merge(
            FirewallWorkspaceViewData::for(
                $this->server,
                $this,
                $needsRules,
                $needsActivity,
                $activityItems,
            ),
            [
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
                'bundledTemplates' => $bundledTemplates,
                'bundledAppliedMap' => $bundledAppliedMap,
                'savedTemplates' => $savedTemplates,
                'activityItems' => $activityItems,
                'sshNotCovered' => $sshNotCovered,
                'applyFirewallConfirmMessage' => $this->disruptiveConfirmMessage(__('Apply firewall rules')),
                'defaultPolicies' => $defaultPolicies,
                'loggingLevel' => $loggingLevel,
                'notifChannels' => $needsNotifications ? $this->assignableFirewallNotificationChannels() : collect(),
                'notifSubscriptions' => $needsNotifications ? $this->firewallNotificationSubscriptions() : collect(),
                'notifEventLabels' => $needsNotifications ? $this->firewallEventLabels() : [],
            ],
        ));
    }

    /**
     * Merge `firewallApplyLogs` and `firewallAuditEvents` into a single chronological timeline.
     * Audit's `EVENT_APPLY` rows are dropped because the matching apply log carries the same
     * fact in a richer shape (transcript + rules_hash + rule_count). Each item is a small
     * shape the view can branch on: `kind=apply` (expandable, with output) vs `kind=audit`
     * (compact one-liner).
     *
     * @return list<array{kind: string, at: \Carbon\Carbon|null, key: string, log?: ServerFirewallApplyLog, event?: ServerFirewallAuditEvent}>
     */
    /**
     * Activity timeline window size. Grows by {@see ACTIVITY_PAGE_SIZE} each time the operator
     * clicks "Load older"; capped at {@see ACTIVITY_MAX_VISIBLE} to keep render costs sane.
     */
    public int $activity_visible = 60;

    public const ACTIVITY_PAGE_SIZE = 60;

    public const ACTIVITY_MAX_VISIBLE = 600;

    public bool $activity_exhausted = false;

    public function loadMoreFirewallActivity(): void
    {
        $this->activity_visible = min(
            self::ACTIVITY_MAX_VISIBLE,
            $this->activity_visible + self::ACTIVITY_PAGE_SIZE,
        );
    }

    protected function buildActivityItems(): array
    {
        // Pull a bit more than $activity_visible from each source so the merge has enough rows
        // to fill the window even after $activity_visible items get cut by sort order. We cap
        // each source at 2× visible because that's the worst case (all from one source).
        $sourceLimit = max($this->activity_visible * 2, self::ACTIVITY_PAGE_SIZE * 2);
        $applyLogs = $this->server->firewallApplyLogs()->limit($sourceLimit)->get();
        $auditEvents = $this->server->firewallAuditEvents()
            ->where('event', '!=', ServerFirewallAuditEvent::EVENT_APPLY)
            ->limit($sourceLimit)
            ->get();

        // Both rowsets reference the same `users` table; eager-loading via the relations
        // would issue two `users where id in (...)` queries that often return identical
        // rows (one operator does most of the work on a server). Pull the union once and
        // attach manually so we hit the table at most once per render.
        $userIds = $applyLogs->pluck('user_id')
            ->merge($auditEvents->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();
        $users = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get()->keyBy('id');
        $applyLogs->each(fn ($log) => $log->setRelation('user', $log->user_id ? $users->get($log->user_id) : null));
        $auditEvents->each(fn ($ev) => $ev->setRelation('user', $ev->user_id ? $users->get($ev->user_id) : null));

        $items = [];
        foreach ($applyLogs as $log) {
            $items[] = [
                'kind' => 'apply',
                'at' => $log->created_at,
                'key' => 'apply-'.$log->id,
                'log' => $log,
            ];
        }
        foreach ($auditEvents as $ev) {
            $items[] = [
                'kind' => 'audit',
                'at' => $ev->created_at,
                'key' => 'audit-'.$ev->id,
                'event' => $ev,
            ];
        }

        usort($items, function (array $a, array $b): int {
            $at = $a['at']?->getTimestamp() ?? 0;
            $bt = $b['at']?->getTimestamp() ?? 0;

            return $bt <=> $at;
        });

        // "Exhausted" = we asked for more than we got from both sources, OR the cap is hit. The
        // view uses this to hide the "Load older" button when there's nothing more to show.
        $this->activity_exhausted = count($items) <= $this->activity_visible
            || $this->activity_visible >= self::ACTIVITY_MAX_VISIBLE;

        return array_slice($items, 0, $this->activity_visible);
    }

    /**
     * Stable key for matching a panel rule against a bundled-template rule. Lower-cases protocol
     * and action so the match doesn't trip on display-case mismatches. Port is the integer
     * value (or empty for ICMP-style rules where port is null on both sides).
     */
    protected function ruleMatchKey(?int $port, ?string $protocol, ?string $action, ?string $source): string
    {
        return implode('|', [
            $port === null ? '' : (string) $port,
            strtolower((string) $protocol),
            strtolower((string) $action),
            strtolower(trim((string) $source)),
        ]);
    }
}
