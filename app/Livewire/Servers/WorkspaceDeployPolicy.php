<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerDeployPolicyGuard;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceDeployPolicy extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.deploy_windows';

    public bool $policy_enabled = false;

    public string $policy_timezone = '';

    public string $policy_message = '';

    /** @var list<array{days: list<string>, start: string, end: string}> */
    public array $deny_rules = [];

    public function mount(Server $server, ServerDeployPolicyGuard $guard): void
    {
        $this->bootWorkspace($server);
        abort_unless($server->isVmHost(), 404);

        $policy = $guard->policyForServer($server);
        $this->policy_enabled = (bool) ($policy['enabled'] ?? false);
        $this->policy_timezone = (string) ($policy['timezone'] ?? config('app.timezone'));
        $this->policy_message = (string) ($policy['message'] ?? '');
        $this->deny_rules = is_array($policy['deny_rules'] ?? null) ? $policy['deny_rules'] : [];
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

    public function savePolicy(ServerDeployPolicyGuard $guard): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'policy_timezone' => ['required', 'timezone:all'],
            'policy_message' => ['nullable', 'string', 'max:500'],
        ]);

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

        $this->toastSuccess(__('Deploy window policy saved.'));
    }

    public function render(ServerDeployPolicyGuard $guard): View
    {
        $report = $guard->report($this->server);

        return view('livewire.servers.workspace-deploy-policy', [
            'report' => $report,
            'currentAllowed' => $report['evaluation']['allowed'],
            'blockReason' => $report['evaluation']['reason'],
            'nextAllowedAt' => $report['evaluation']['next_allowed_at'],
            'dayOptions' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        ]);
    }
}
