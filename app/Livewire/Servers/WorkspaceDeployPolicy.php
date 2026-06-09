<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesDeployPolicyNotifications;
use App\Models\Server;
use App\Models\User;
use App\Services\Notifications\ServerDeployPolicyNotificationDispatcher;
use App\Services\Servers\ServerDeployPolicyGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

/**
 * Server-wide deploy deny windows, live status, and per-site coverage.
 *
 * When {@see workspace.deploy_windows} is off but
 * {@see workspace.deploy_windows_preview} is on, the canonical /deploy-policy
 * URL renders the coming-soon teaser in place of the full workspace.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceDeployPolicy extends Component
{
    use RendersWorkspacePlaceholder;
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use CreatesNotificationChannelInline;
    use ManagesDeployPolicyNotifications;

    protected string $requiredFeature = 'workspace.deploy_windows';

    /** @var list<string> */
    public const POLICY_TABS = ['overview', 'schedule', 'coverage', 'activity', 'notifications'];

    /** In-page tab: overview | schedule | coverage | activity | notifications. */
    #[Url(as: 'tab', except: 'overview', history: true)]
    public string $policy_tab = 'overview';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public bool $policy_enabled = false;

    public string $policy_timezone = '';

    public string $policy_message = '';

    /** @var list<array{days: list<string>, start: string, end: string}> */
    public array $deny_rules = [];

    public function mount(Server $server): void
    {
        abort_unless($server->isVmHost(), 404);

        if (! Feature::active('workspace.deploy_windows')) {
            if (workspace_deploy_windows_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->bootWorkspace($server);

        $policy = app(ServerDeployPolicyGuard::class)->policyForServer($server);
        $this->policy_enabled = (bool) ($policy['enabled'] ?? false);
        $this->policy_timezone = (string) ($policy['timezone'] ?? config('app.timezone'));
        $this->policy_message = (string) ($policy['message'] ?? '');
        $this->deny_rules = is_array($policy['deny_rules'] ?? null) ? $policy['deny_rules'] : [];
    }

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }

    public function setPolicyTab(string $tab): void
    {
        $this->policy_tab = in_array($tab, self::POLICY_TABS, true) ? $tab : 'overview';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->policy_tab = 'notifications';
        $this->notif_channel_id = $channelId;
    }

    public function applyWeekendFreezePreset(ServerDeployPolicyGuard $guard): void
    {
        $this->deny_rules = $guard->weekendFreezePreset();
    }

    public function addDenyRule(): void
    {
        $this->deny_rules[] = ['days' => ['fri'], 'start' => '17:00', 'end' => '23:59'];
    }

    public function removeDenyRule(int $index): void
    {
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
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-deploy-policy-preview');
        }

        $report = $guard->report($this->server);
        $onNotifications = $this->policy_tab === 'notifications';

        return view('livewire.servers.workspace-deploy-policy', [
            'report' => $report,
            'currentAllowed' => $report['evaluation']['allowed'],
            'blockReason' => $report['evaluation']['reason'],
            'nextAllowedAt' => $report['evaluation']['next_allowed_at'],
            'dayOptions' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            'notifChannels' => $onNotifications ? $this->assignableDeployPolicyNotificationChannels() : collect(),
            'notifSubscriptions' => $onNotifications ? $this->deployPolicyNotificationSubscriptions() : collect(),
            'notifEventLabels' => $onNotifications ? $this->deployPolicyEventLabels() : [],
        ]);
    }
}
