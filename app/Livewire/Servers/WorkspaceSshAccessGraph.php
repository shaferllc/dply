<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerSshSession;
use App\Models\UserSshKey;
use App\Services\Servers\ServerSshAccessGraph;
use App\Services\Servers\ServerSshSessionManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSshAccessGraph extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.ssh_access_graph';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public string $session_name = '';

    public string $session_public_key = '';

    public int $session_duration_hours = 24;

    public string $session_linux_user = '';

    public ?string $revoke_session_id = null;

    public function mount(Server $server): void
    {
        if (! Feature::active('workspace.ssh_access_graph')) {
            if (workspace_ssh_access_graph_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);
                abort_unless($server->isVmHost(), 404);

                return;
            }

            abort(404);
        }

        $this->bootWorkspace($server);
        abort_unless($server->isVmHost(), 404);
        $this->session_linux_user = (string) ($server->ssh_user ?: 'dply');
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

    public function openGrantSessionModal(): void
    {
        abort_unless(Feature::active('workspace.ssh_sessions'), 404);
        $this->resetValidation();
        $this->dispatch('open-modal', 'grant-ssh-session');
    }

    public function grantSession(ServerSshSessionManager $manager): void
    {
        abort_unless(Feature::active('workspace.ssh_sessions'), 404);
        $this->authorize('update', $this->server);

        $maxHours = max(1, (int) config('server_ssh_sessions.max_duration_hours', 168));

        $this->validate([
            'session_name' => ['required', 'string', 'max:120'],
            'session_public_key' => ['required', 'string', 'max:8000'],
            'session_duration_hours' => ['required', 'integer', 'min:1', 'max:'.$maxHours],
            'session_linux_user' => ['nullable', 'string', 'max:64'],
        ]);

        if (! UserSshKey::publicKeyLooksValid($this->session_public_key)) {
            $this->addError('session_public_key', __('That does not look like a valid SSH public key.'));

            return;
        }

        $manager->grant(
            $this->server,
            auth()->user(),
            $this->session_name,
            $this->session_public_key,
            Carbon::now()->addHours($this->session_duration_hours),
            $this->session_linux_user,
        );

        $this->session_name = '';
        $this->session_public_key = '';
        $this->session_duration_hours = 24;
        $this->dispatch('close-modal', 'grant-ssh-session');
        $this->toastSuccess(__('Temporary SSH session granted and synced.'));
    }

    public function openRevokeSessionModal(string $sessionId): void
    {
        abort_unless(Feature::active('workspace.ssh_sessions'), 404);
        $this->revoke_session_id = $sessionId;
        $this->dispatch('open-modal', 'revoke-ssh-session');
    }

    public function revokeSession(ServerSshSessionManager $manager): void
    {
        abort_unless(Feature::active('workspace.ssh_sessions'), 404);
        $this->authorize('update', $this->server);

        $session = ServerSshSession::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->revoke_session_id)
            ->firstOrFail();

        $manager->revoke($session);

        $this->revoke_session_id = null;
        $this->dispatch('close-modal', 'revoke-ssh-session');
        $this->toastSuccess(__('SSH session revoked.'));
    }

    public function render(ServerSshAccessGraph $graph): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-ssh-access-graph-preview');
        }

        $this->server->refresh();

        return view('livewire.servers.workspace-ssh-access-graph', [
            'report' => $graph->forServer($this->server),
            'sessionsEnabled' => Feature::active('workspace.ssh_sessions'),
            'durationPresets' => config('server_ssh_sessions.duration_presets', [4, 8, 24, 72, 168]),
        ]);
    }
}
