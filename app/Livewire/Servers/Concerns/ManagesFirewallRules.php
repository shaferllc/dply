<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Forms\FirewallRuleForm;
use App\Models\ServerFirewallAuditEvent;
use App\Models\ServerFirewallRule;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesFirewallRules
{
    public FirewallRuleForm $form;

    public ?string $editing_rule_id = null;

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
