<?php

namespace App\Livewire\Servers;

use App\Jobs\CreateServerSystemUserJob;
use App\Jobs\DeleteOrphanSystemUsersJob;
use App\Jobs\DeleteServerSystemUserJob;
use App\Jobs\SyncServerSystemUsersJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Sites\Show;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceSystemUsers extends Component
{
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.system_users';

    use ConfirmsActionWithModal;
    use DismissesConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    protected function consoleActionSubject(): Model
    {
        return $this->server;
    }

    public string $new_username = '';

    public bool $new_sudo = false;

    public string $new_shell = '/bin/bash';

    public bool $new_add_web_group = true;

    public string $remove_username = '';

    public string $remove_confirm = '';

    /**
     * Usernames whose removal is queued/running. Drives the per-row "Removing…"
     * spinner so the operator gets feedback between dispatching the job and the
     * worker landing the change in `server_system_users`.
     *
     * @var list<string>
     */
    public array $pending_remove_usernames = [];

    /** @var list<array{username: string, site_count: int, is_protected: bool, is_orphan: bool, uid: int|null, home: string, shell: string, groups: list<string>, sites: list<array{id: string, name: string}>}> */
    public array $remote_rows = [];

    public ?string $list_error = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    /**
     * Dispatches a queued sync against the server's `/etc/passwd`. The console
     * banner picks up progress/errors via the {@see SyncServerSystemUsersJob}
     * console_actions run; the table re-hydrates from DB on every poll because
     * {@see render()} pulls the latest snapshot each pass.
     */
    public function loadUsers(): void
    {
        $this->authorize('update', $this->server);
        $this->list_error = null;

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->list_error = __('The server must be ready with SSH before loading system users.');

            return;
        }

        $this->seedQueuedSystemUserAction(__('Syncing system users from :host …', [
            'host' => $this->server->getSshConnectionString(),
        ]));

        SyncServerSystemUsersJob::dispatch($this->server->id, auth()->id());

        $this->toastSuccess(__('Sync queued — the console banner will update when the worker finishes.'));
    }

    /**
     * Seeds a queued console_actions row for the server-scoped system_user
     * banner. Mirrors the helper on {@see Show::seedQueuedConsoleAction()}
     * but scoped to a Server subject; auto-dismisses any prior terminal rows so
     * the banner always shows the current run, not a stale completion.
     */
    private function seedQueuedSystemUserAction(?string $label = null): void
    {
        ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        ConsoleAction::query()->create([
            'subject_type' => $this->server->getMorphClass(),
            'subject_id' => $this->server->id,
            'kind' => 'system_user',
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => $label,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    public function openCreateModal(): void
    {
        $this->authorize('update', $this->server);
        $this->resetErrorBag();
        $this->new_username = '';
        $this->new_sudo = false;
        $this->new_shell = '/bin/bash';
        $this->new_add_web_group = true;
        $this->dispatch('open-modal', 'server-system-user-create-modal');
    }

    public function closeCreateModal(): void
    {
        $this->dispatch('close-modal', 'server-system-user-create-modal');
    }

    public function queueCreate(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));

            return;
        }

        $this->validate([
            'new_username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'new_sudo' => ['boolean'],
            'new_shell' => ['required', Rule::in(['/bin/bash', '/bin/sh', '/usr/sbin/nologin'])],
            'new_add_web_group' => ['boolean'],
        ]);

        $extraGroups = [];
        if ($this->new_add_web_group) {
            $webGroup = trim((string) config('site_settings.vm_site_file_web_group', 'www-data'));
            if ($webGroup !== '') {
                $extraGroups[] = $webGroup;
            }
        }

        $this->seedQueuedSystemUserAction(__('Creating system user :user on :host …', [
            'user' => $this->new_username,
            'host' => $this->server->getSshConnectionString(),
        ]));

        CreateServerSystemUserJob::dispatch(
            $this->server->id,
            $this->new_username,
            $this->new_sudo,
            auth()->id(),
            $this->new_shell,
            $extraGroups,
        );

        $this->closeCreateModal();
        $this->toastSuccess(__('System user creation queued — watch the console banner for progress.'));
    }

    public function openRemoveModal(string $username): void
    {
        $this->authorize('update', $this->server);
        $this->resetErrorBag();

        $allowed = collect($this->remote_rows)->pluck('username')->filter()->all();
        if (! in_array($username, $allowed, true)) {
            $this->toastError(__('Reload the user list before removing.'));

            return;
        }

        $this->remove_username = $username;
        $this->remove_confirm = '';
        $this->dispatch('open-modal', 'server-system-user-remove-modal');
    }

    public function closeRemoveModal(): void
    {
        $this->dispatch('close-modal', 'server-system-user-remove-modal');
    }

    public function queueRemove(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));
            $this->closeRemoveModal();

            return;
        }

        $allowed = collect($this->remote_rows)->pluck('username')->filter()->all();
        $this->validate([
            'remove_username' => ['required', 'string', 'max:64', Rule::in($allowed)],
            'remove_confirm' => ['required', 'same:remove_username'],
        ]);

        $this->seedQueuedSystemUserAction(__('Removing system user :user from :host …', [
            'user' => $this->remove_username,
            'host' => $this->server->getSshConnectionString(),
        ]));

        if (! in_array($this->remove_username, $this->pending_remove_usernames, true)) {
            $this->pending_remove_usernames[] = $this->remove_username;
        }

        DeleteServerSystemUserJob::dispatch($this->server->id, $this->remove_username, auth()->id());

        $this->closeRemoveModal();
        $this->toastSuccess(__('User removal queued — watch the console banner for progress.'));
    }

    /**
     * Opens the shared confirm-action modal pre-wired to {@see queueRemoveOrphans()}.
     * Re-derives orphans from $remote_rows at click time so the count in the prompt
     * matches what the operator currently sees, not whatever was on the page when
     * they first loaded it.
     */
    public function openRemoveOrphansConfirm(): void
    {
        $this->authorize('update', $this->server);

        $orphans = $this->currentOrphanUsernames();
        if ($orphans === []) {
            $this->toastError(__('No orphan accounts to remove.'));

            return;
        }

        $preview = count($orphans) <= 6
            ? implode(', ', $orphans)
            : implode(', ', array_slice($orphans, 0, 6)).', …';

        $this->openConfirmActionModal(
            'queueRemoveOrphans',
            [],
            __('Remove :count orphan accounts?', ['count' => count($orphans)]),
            __('Dply will run userdel for each of: :list. Protected accounts and any user still owning a site are skipped automatically.', ['list' => $preview]),
            __('Remove all orphans'),
            true,
        );
    }

    public function queueRemoveOrphans(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));

            return;
        }

        $orphans = $this->currentOrphanUsernames();
        if ($orphans === []) {
            $this->toastError(__('No orphan accounts to remove.'));

            return;
        }

        $this->seedQueuedSystemUserAction(__('Removing :count orphan accounts from :host …', [
            'count' => count($orphans),
            'host' => $this->server->getSshConnectionString(),
        ]));

        foreach ($orphans as $username) {
            if (! in_array($username, $this->pending_remove_usernames, true)) {
                $this->pending_remove_usernames[] = $username;
            }
        }

        DeleteOrphanSystemUsersJob::dispatch($this->server->id, $orphans, auth()->id());

        $this->toastSuccess(__('Bulk removal queued — watch the console banner for progress.'));
    }

    /**
     * @return list<string>
     */
    private function currentOrphanUsernames(): array
    {
        return collect($this->remote_rows)
            ->filter(static fn (array $r): bool => ! empty($r['is_orphan']) && empty($r['is_protected']) && (int) ($r['site_count'] ?? 0) === 0)
            ->pluck('username')
            ->filter()
            ->values()
            ->all();
    }

    public function render(ServerSystemUserService $service): View
    {
        // Hydrate on every render (mount + each wire:poll tick) so the table
        // tracks the persisted snapshot — Sync/Create/Remove jobs rewrite the
        // rows in the background, the banner's 4s self-poll re-renders us,
        // and the operator sees fresh data without manually refreshing.
        $this->remote_rows = $service->storedSystemUsersWithMetadata($this->server);

        // Per-row "Removing…" state clears once no system_user run is in flight
        // for this server. Failures keep the row visible; success drops it from
        // $remote_rows. Either way, after the banner settles the spinner stops.
        if ($this->pending_remove_usernames !== [] && ! $this->hasInFlightSystemUserAction()) {
            $this->pending_remove_usernames = [];
        }

        return view('livewire.servers.workspace-system-users');
    }

    private function hasInFlightSystemUserAction(): bool
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'system_user')
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->exists();
    }
}
