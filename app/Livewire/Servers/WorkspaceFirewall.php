<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Servers\Concerns\GuardsDisruptiveActions;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesFirewallActivity;
use App\Livewire\Servers\Concerns\ManagesFirewallApply;
use App\Livewire\Servers\Concerns\ManagesFirewallBulkRules;
use App\Livewire\Servers\Concerns\ManagesFirewallDiagnostics;
use App\Livewire\Servers\Concerns\ManagesFirewallImportExport;
use App\Livewire\Servers\Concerns\ManagesFirewallNotifications;
use App\Livewire\Servers\Concerns\ManagesFirewallRules;
use App\Livewire\Servers\Concerns\ManagesFirewallWorkspaceAdvanced;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\FirewallRuleTemplate;
use App\Models\Server;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\FirewallWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

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
    use ManagesFirewallActivity;
    use ManagesFirewallApply;
    use ManagesFirewallBulkRules;
    use ManagesFirewallDiagnostics;
    use ManagesFirewallImportExport;
    use ManagesFirewallNotifications;
    use ManagesFirewallRules;
    use ManagesFirewallWorkspaceAdvanced;
    use RendersWorkspacePlaceholder;

    protected ?string $lastUfwHostSyncError = null;

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

    /** A queued/running apply older than this is treated as stuck so the operator can re-dispatch. */
    public const APPLY_STALE_THRESHOLD_SECONDS = 300;

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

    public const ACTIVITY_PAGE_SIZE = 60;

    public const ACTIVITY_MAX_VISIBLE = 600;
}
