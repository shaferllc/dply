<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesDeployPolicyNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Notifications\Services\ServerDeployPolicyNotificationDispatcher;
use App\Services\Servers\ServerDeployPolicyGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Deploy activity AND deploy-window policy for a single server, unified on one
 * tabbed page:
 *
 *   - History       — every deployment recorded for sites on this server, with
 *                     policy-blocked rows enriched to show which deny window
 *                     held them back.
 *   - Deploy Windows — live enforcement status + the deny-window rule editor.
 *   - Coverage      — per-site coverage of the policy.
 *   - Notifications — bind channels to deploy-window events.
 *
 * Deploy windows are GA: the policy surface is always available on VM hosts
 * (no feature flag). Viewing requires `view` on the server; editing the policy
 * requires `update` — the editor renders read-only otherwise.
 */
class Deploys extends Component
{
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use ManagesDeployPolicyNotifications;
    use WithPagination;

    /** @var list<string> */
    public const TABS = ['history', 'deploy-windows', 'coverage', 'notifications'];

    public const ALLOWED_STATUSES = [
        SiteDeployment::STATUS_RUNNING,
        SiteDeployment::STATUS_SUCCESS,
        SiteDeployment::STATUS_FAILED,
        SiteDeployment::STATUS_SKIPPED,
    ];

    #[Url(as: 'tab', except: 'history', history: true)]
    public string $tab = 'history';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    // ── Deploy-window policy form state ───────────────────────────────────
    public bool $policy_enabled = false;

    public string $policy_timezone = '';

    public string $policy_message = '';

    /** @var list<array{days: list<string>, start: string, end: string}> */
    public array $deny_rules = [];

    public function mount(Server $server): void
    {
        // authorize('view', $server) + non-VM-host redirect guard.
        $this->bootWorkspace($server);

        $policy = app(ServerDeployPolicyGuard::class)->policyForServer($server);
        $this->policy_enabled = (bool) ($policy['enabled'] ?? false);
        $this->policy_timezone = (string) ($policy['timezone'] ?? config('app.timezone'));
        $this->policy_message = (string) ($policy['message'] ?? '');
        $this->deny_rules = is_array($policy['deny_rules'] ?? null) ? $policy['deny_rules'] : [];
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'history';
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->statusFilter = '';
        $this->resetPage();
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    public function applyWeekendFreezePreset(ServerDeployPolicyGuard $guard): void
    {
        $this->authorize('update', $this->server);
        $this->deny_rules = $guard->weekendFreezePreset();
    }

    public function addDenyRule(): void
    {
        $this->authorize('update', $this->server);
        $this->deny_rules[] = ['days' => ['fri'], 'start' => '17:00', 'end' => '23:59'];
    }

    public function removeDenyRule(int $index): void
    {
        $this->authorize('update', $this->server);
        if (isset($this->deny_rules[$index])) {
            unset($this->deny_rules[$index]);
            $this->deny_rules = array_values($this->deny_rules);
        }
    }

    public function savePolicy(ServerDeployPolicyGuard $guard, ServerDeployPolicyNotificationDispatcher $notifier): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'policy_timezone' => ['required', 'timezone:all'],
            'policy_message' => ['nullable', 'string', 'max:500'],
        ]);

        $wasEnabled = (bool) ($guard->policyForServer($this->server)['enabled'] ?? false);

        $policy = $guard->normalizePolicy([
            'enabled' => $this->policy_enabled,
            'timezone' => $this->policy_timezone,
            'message' => $this->policy_message,
            'deny_rules' => $this->deny_rules,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $key = (string) config('server_deploy_policy.meta_key', 'deploy_policy');
        $meta[$key] = $policy;
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();

        // Announce enforcement flips to anyone routing deploy-window events.
        $nowEnabled = (bool) $policy['enabled'];
        if ($nowEnabled !== $wasEnabled) {
            $notifier->notify(
                $this->server,
                $nowEnabled ? 'policy_enabled' : 'policy_disabled',
                [
                    __('Timezone: :tz', ['tz' => (string) $policy['timezone']]),
                    trans_choice(':count deny rule configured|:count deny rules configured', count($policy['deny_rules']), ['count' => count($policy['deny_rules'])]),
                ],
                Auth::user(),
            );
        }

        $this->toastSuccess(__('Deploy window policy saved.'));
    }

    public function render(ServerDeployPolicyGuard $guard): View
    {
        // The rich per-site report (DB-backed) is only needed by the policy
        // tabs; History + Notifications get by on the cheap evaluation that the
        // banner needs anyway.
        $needsReport = in_array($this->tab, ['deploy-windows', 'coverage'], true);

        if ($needsReport) {
            $report = $guard->report($this->server);
            $evaluation = $report['evaluation'];
            $policy = $report['policy'];
            $overall = $report['overall'];
        } else {
            $report = null;
            $evaluation = $guard->evaluateServer($this->server);
            $policy = $evaluation['policy'];
            $enabled = (bool) ($policy['enabled'] ?? false);
            $overall = ! $enabled ? 'disabled' : ($evaluation['allowed'] ? 'allowed' : 'blocked');
        }

        $data = [
            'overall' => $overall,
            'currentAllowed' => (bool) $evaluation['allowed'],
            'blockReason' => $evaluation['reason'] ?? null,
            'nextAllowedAt' => $evaluation['next_allowed_at'] ?? null,
            'policyTimezone' => (string) ($policy['timezone'] ?? config('app.timezone')),
            'ruleCount' => is_array($policy['deny_rules'] ?? null) ? count($policy['deny_rules']) : 0,
            'canUpdate' => Auth::user()?->can('update', $this->server) ?? false,
            'dayOptions' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            'report' => $report,
        ];

        if ($this->tab === 'history') {
            $data += $this->historyData();
        }

        if ($this->tab === 'notifications') {
            $data += [
                'notifChannels' => $this->assignableDeployPolicyNotificationChannels(),
                'notifSubscriptions' => $this->deployPolicyNotificationSubscriptions(),
                'notifEventLabels' => $this->deployPolicyEventLabels(),
            ];
        }

        return view('livewire.servers.deploys', $data)->layout('layouts.app');
    }

    /**
     * @return array{deployments: \Illuminate\Contracts\Pagination\LengthAwarePaginator, sites: \Illuminate\Support\Collection, statuses: list<string>}
     */
    private function historyData(): array
    {
        $siteIds = Site::query()
            ->where('server_id', $this->server->id)
            ->pluck('id');

        $query = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('started_at');

        if (in_array($this->statusFilter, self::ALLOWED_STATUSES, true)) {
            $query->where('status', $this->statusFilter);
        }

        $deployments = $query->paginate(25);

        $sites = Site::query()
            ->whereIn('id', $deployments->pluck('site_id')->unique())
            ->get(['id', 'name', 'slug', 'server_id', 'runtime'])
            ->keyBy('id');

        return [
            'deployments' => $deployments,
            'sites' => $sites,
            'statuses' => self::ALLOWED_STATUSES,
        ];
    }
}
