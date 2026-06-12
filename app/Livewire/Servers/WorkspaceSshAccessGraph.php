<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use App\Models\ServerRemoteAccessEvent;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\ServerSshSession;
use App\Models\UserSshKey;
use App\Services\Servers\ServerSshAccessWorkspaceData;
use App\Services\Servers\ServerSshSessionManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceSshAccessGraph extends Component
{
    use InteractsWithServerWorkspace;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.ssh_access_graph';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public string $session_name = '';

    public string $session_public_key = '';

    public int $session_duration_hours = 24;

    public string $session_linux_user = '';

    public ?string $revoke_session_id = null;

    /** Time window for the access-over-time chart: 7d, 30d, or 90d. */
    public string $timeline_range = '30d';

    /** Detail payload for the currently-open access-event modal. */
    public ?array $selectedEvent = null;

    /** Current page of the recent-changes (events) list. */
    public int $eventsPage = 1;

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

    /**
     * Build the detail payload for a recent-changes entry and open the modal.
     */
    public function showEventDetail(string $type, string $id): void
    {
        $this->selectedEvent = match ($type) {
            'platform' => $this->platformEventDetail($id),
            'session' => $this->sessionEventDetail($id),
            'audit' => $this->auditEventDetail($id),
            default => null,
        };

        if ($this->selectedEvent !== null) {
            $this->dispatch('open-modal', 'ssh-access-event');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function platformEventDetail(string $id): ?array
    {
        $event = ServerRemoteAccessEvent::query()
            ->where('server_id', $this->server->id)
            ->with('user')
            ->find($id);

        if ($event === null) {
            return null;
        }

        $rows = [
            ['label' => __('Started'), 'value' => $event->started_at?->format('M j, Y · H:i') ?? '—'],
        ];

        if ($event->finished_at !== null) {
            $rows[] = ['label' => __('Finished'), 'value' => $event->finished_at->format('M j, Y · H:i')];
        }

        $duration = $event->durationSeconds();
        if ($duration !== null) {
            $rows[] = ['label' => __('Duration'), 'value' => $this->humanDuration($duration)];
        }

        $rows[] = ['label' => __('Commands run'), 'value' => (string) $event->command_count];

        if ($event->linux_user) {
            $rows[] = ['label' => __('Linux user'), 'value' => $event->linux_user, 'mono' => true];
        }
        if ($event->credential_role) {
            $rows[] = ['label' => __('Credential'), 'value' => $event->credential_role];
        }
        if ($event->source) {
            $rows[] = ['label' => __('Source'), 'value' => $event->source];
        }

        $rows[] = ['label' => __('Initiated by'), 'value' => $event->user?->name ?? $event->user?->email ?? __('Dply platform')];

        $jobUuid = (string) data_get($event->meta, 'job_uuid', '');
        if ($jobUuid !== '') {
            $rows[] = ['label' => __('Job'), 'value' => $jobUuid, 'mono' => true];
        }

        return [
            'eyebrow' => __('Dply platform access'),
            'title' => (string) $event->label,
            'tone' => $event->failed ? 'rose' : ($event->isInFlight() ? 'amber' : 'sky'),
            'status' => $event->failed ? __('Failed') : ($event->isInFlight() ? __('In progress') : __('Completed')),
            'rows' => $rows,
            'command' => (string) data_get($event->meta, 'last_command', '') ?: null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sessionEventDetail(string $id): ?array
    {
        $session = ServerSshSession::query()
            ->where('server_id', $this->server->id)
            ->with('createdBy')
            ->find($id);

        if ($session === null) {
            return null;
        }

        $revoked = $session->revoked_at !== null;
        $expired = ! $revoked && $session->expires_at->isPast();

        $rows = [
            ['label' => __('Granted'), 'value' => $session->provisioned_at?->format('M j, Y · H:i') ?? '—'],
            ['label' => __('Expires'), 'value' => $session->expires_at->format('M j, Y · H:i')],
        ];

        if ($revoked) {
            $rows[] = ['label' => __('Revoked'), 'value' => $session->revoked_at->format('M j, Y · H:i')];
        }

        $rows[] = ['label' => __('Created by'), 'value' => $session->createdBy?->name ?? $session->createdBy?->email ?? __('Unknown')];

        if ($session->target_linux_user) {
            $rows[] = ['label' => __('Linux user'), 'value' => $session->target_linux_user, 'mono' => true];
        }
        if ($session->public_key_fingerprint) {
            $rows[] = ['label' => __('Key fingerprint'), 'value' => $session->public_key_fingerprint, 'mono' => true];
        }

        return [
            'eyebrow' => __('Temporary session'),
            'title' => (string) $session->name,
            'tone' => $revoked ? 'rose' : ($expired ? 'slate' : 'emerald'),
            'status' => $revoked ? __('Revoked') : ($expired ? __('Expired') : __('Active')),
            'rows' => $rows,
            'command' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function auditEventDetail(string $id): ?array
    {
        $event = ServerSshKeyAuditEvent::query()
            ->where('server_id', $this->server->id)
            ->with('user')
            ->find($id);

        if ($event === null) {
            return null;
        }

        $label = match ($event->event) {
            ServerSshKeyAuditEvent::EVENT_KEY_CREATED => __('Key added'),
            ServerSshKeyAuditEvent::EVENT_KEY_DELETED => __('Key removed'),
            ServerSshKeyAuditEvent::EVENT_KEY_UPDATED => __('Key updated'),
            ServerSshKeyAuditEvent::EVENT_SYNC_COMPLETED => __('Keys synced'),
            ServerSshKeyAuditEvent::EVENT_SYNC_BLOCKED => __('Sync blocked'),
            ServerSshKeyAuditEvent::EVENT_ORG_KEY_DEPLOYED => __('Org key deployed'),
            ServerSshKeyAuditEvent::EVENT_TEAM_KEY_DEPLOYED => __('Team key deployed'),
            ServerSshKeyAuditEvent::EVENT_BULK_IMPORTED => __('Keys bulk-imported'),
            ServerSshKeyAuditEvent::EVENT_SETTINGS_UPDATED => __('Settings updated'),
            default => (string) $event->event,
        };

        $rows = [
            ['label' => __('When'), 'value' => $event->created_at?->format('M j, Y · H:i') ?? '—'],
            ['label' => __('Actor'), 'value' => $event->user?->name ?? $event->user?->email ?? __('System')],
        ];

        $name = (string) data_get($event->meta, 'name', '');
        if ($name !== '') {
            $rows[] = ['label' => __('Key'), 'value' => $name, 'mono' => true];
        }

        $linuxUser = (string) data_get($event->meta, 'target_linux_user', '');
        if ($linuxUser !== '') {
            $rows[] = ['label' => __('Linux user'), 'value' => $linuxUser, 'mono' => true];
        }

        if ($event->ip_address) {
            $rows[] = ['label' => __('IP address'), 'value' => $event->ip_address, 'mono' => true];
        }

        return [
            'eyebrow' => __('Key activity'),
            'title' => $label,
            'tone' => $event->event === ServerSshKeyAuditEvent::EVENT_SYNC_BLOCKED ? 'rose' : 'slate',
            'status' => null,
            'rows' => $rows,
            'command' => null,
        ];
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return trans_choice(':count second|:count seconds', $seconds, ['count' => $seconds]);
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            $rem = $seconds % 60;

            return $rem > 0
                ? __(':m min :s s', ['m' => $minutes, 's' => $rem])
                : trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]);
        }

        $hours = intdiv($minutes, 60);
        $remMinutes = $minutes % 60;

        return $remMinutes > 0
            ? __(':h h :m min', ['h' => $hours, 'm' => $remMinutes])
            : trans_choice(':count hour|:count hours', $hours, ['count' => $hours]);
    }

    public function updatedTimelineRange(string $value): void
    {
        if (! in_array($value, ['7d', '30d', '90d'], true)) {
            $this->timeline_range = '30d';
        }

        $this->eventsPage = 1;
    }

    public function render(ServerSshAccessWorkspaceData $workspaceData): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-ssh-access-graph-preview');
        }

        $payload = $workspaceData->for($this->server, auth()->user(), $this->timeline_range);

        $perPage = max(1, (int) config('server_ssh_access.timeline_events_per_page', 8));
        $allEvents = $payload['timeline']['events'];
        $totalEvents = count($allEvents);
        $totalPages = max(1, (int) ceil($totalEvents / $perPage));
        $this->eventsPage = min(max(1, $this->eventsPage), $totalPages);

        $payload['timeline']['events'] = array_slice($allEvents, ($this->eventsPage - 1) * $perPage, $perPage);

        return view('livewire.servers.workspace-ssh-access-graph', [
            'report' => $payload['report'],
            'timeline' => $payload['timeline'],
            'sessionsEnabled' => Feature::active('workspace.ssh_sessions'),
            'durationPresets' => config('server_ssh_sessions.duration_presets', [4, 8, 24, 72, 168]),
            'eventsPagination' => [
                'page' => $this->eventsPage,
                'total_pages' => $totalPages,
                'total' => $totalEvents,
                'per_page' => $perPage,
            ],
        ]);
    }
}
