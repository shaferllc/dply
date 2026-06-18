<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\AssignSystemUserToSiteJob;
use App\Jobs\SiteResetPermissionsJob;
use App\Models\Site;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteSystemUsers
{
    public string $system_user_assign_username = '';

    /**
     * Cached list of usernames present on the server. Populated by
     * {@see loadSystemUsersForPanel()} on first load of the system-user section
     * and used as both the picker's option list and the validation allow-list
     * for {@see queueAssignSystemUser()}. Site-count metadata lives only on the
     * server-level /system-users page now; here we just need the usernames.
     *
     * @var list<array{username: string, site_count: int}>
     */
    public array $system_user_remote_rows = [];

    /**
     * True once we have a definitive account list for the picker — either a
     * fresh SSH probe ran or we seeded a non-empty snapshot from the DB. Gates
     * the "No regular Linux users" empty state so it never shows before a list
     * has actually been fetched (the rows array is empty on first render too).
     */
    public bool $system_users_loaded = false;

    public ?string $system_user_list_error = null;

    /**
     * Pre-fill the system-user picker from the last persisted /etc/passwd
     * snapshot so a returning operator sees existing accounts immediately —
     * no SSH on the render path. "Load system users" then re-probes live.
     */
    protected function seedSystemUsersFromStore(): void
    {
        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        $rows = app(ServerSystemUserService::class)->storedSystemUsersWithMetadata($this->server);
        if ($rows === []) {
            return;
        }

        $this->system_user_remote_rows = $rows;
        $this->system_users_loaded = true;
    }

    public function saveSystemUserSettings(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('System user settings apply to VM-backed sites with managed PHP.'));

            return;
        }

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        $this->validate([
            'php_fpm_user' => 'nullable|string|max:64',
        ]);

        $this->site->update([
            'php_fpm_user' => $this->php_fpm_user !== '' ? $this->php_fpm_user : null,
        ]);
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->toastSuccess(__('System user settings saved.'));
    }

    public function loadSystemUsersForPanel(ServerPasswdUserLister $lister, ServerSystemUserService $service): void
    {
        $this->authorize('update', $this->site);
        $this->system_user_list_error = null;

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->system_user_list_error = __('The server must be ready with SSH before loading system users.');

            return;
        }

        try {
            $this->system_user_remote_rows = $service->listPasswdUsersWithSiteCounts($this->server->fresh(), $lister);
            $this->system_users_loaded = true;
        } catch (\Throwable $e) {
            $this->system_user_list_error = $e->getMessage();
            $this->system_user_remote_rows = [];
        }
    }

    public function openSystemUserAssignModal(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag();

        $allowed = collect($this->system_user_remote_rows)->pluck('username')->filter()->all();
        $this->validate([
            'system_user_assign_username' => ['required', 'string', 'max:64', Rule::in($allowed)],
        ]);

        $this->dispatch('open-modal', 'site-system-user-assign-modal');
    }

    public function openSystemUserResetPermissionsModal(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag();
        $this->dispatch('open-modal', 'site-reset-permissions-modal');
    }

    public function closeSystemUserResetPermissionsModal(): void
    {
        $this->dispatch('close-modal', 'site-reset-permissions-modal');
    }

    public function closeSystemUserAssignModal(): void
    {
        $this->dispatch('close-modal', 'site-system-user-assign-modal');
    }

    public function queueAssignSystemUser(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));

            return;
        }

        $allowed = collect($this->system_user_remote_rows)->pluck('username')->filter()->all();
        $this->validate([
            'system_user_assign_username' => ['required', 'string', 'max:64', Rule::in($allowed)],
        ]);

        AssignSystemUserToSiteJob::dispatch(
            $this->site->id,
            $this->system_user_assign_username,
            auth()->id(),
        );

        $this->closeSystemUserAssignModal();
        $this->toastSuccess(__('System user assignment queued. Refresh in a moment to see updates.'));
    }

    public function queueResetSitePermissions(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldShowSystemUserPanel()) {
            return;
        }

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('The server must be ready with SSH.'));
            $this->closeSystemUserResetPermissionsModal();

            return;
        }

        SiteResetPermissionsJob::dispatch($this->site->id);

        $this->closeSystemUserResetPermissionsModal();
        $this->toastSuccess(__('Reset permissions queued. Refresh in a moment for results.'));
    }

    public function dismissSystemUserOperationBanner(): void
    {
        $this->authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta['system_user_operation']);
        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
        $this->syncFormFromSite();
    }
}
