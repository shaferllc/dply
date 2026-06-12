<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\ServerDeployGitIdentity;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerGitIdentity
{
    public string $manage_auto_updates_interval = 'off';

    public string $git_deploy_identity_name = '';

    public string $git_deploy_identity_email = '';

    protected function hydrateGitDeployIdentityForm(): void
    {
        $identity = app(ServerDeployGitIdentity::class);
        $defaults = $identity->defaults($this->server);
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $git = is_array($meta['manage_tools']['git'] ?? null) ? $meta['manage_tools']['git'] : [];

        $this->git_deploy_identity_name = is_string($git['user_name'] ?? null) && trim($git['user_name']) !== ''
            ? trim($git['user_name'])
            : $defaults['name'];
        $this->git_deploy_identity_email = is_string($git['user_email'] ?? null) && trim($git['user_email']) !== ''
            ? trim($git['user_email'])
            : $defaults['email'];
    }

    public function saveDeployGitIdentity(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server manage settings.'));

            return;
        }

        $this->validate([
            'git_deploy_identity_name' => ['required', 'string', 'max:120'],
            'git_deploy_identity_email' => ['required', 'email', 'max:190'],
        ]);

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $identity = app(ServerDeployGitIdentity::class);
        $deployUser = $identity->deployUser($this->server);
        if ($deployUser === null) {
            $this->toastError(__('This server has no deploy user configured.'));

            return;
        }

        $name = trim($this->git_deploy_identity_name);
        $email = trim($this->git_deploy_identity_email);

        $this->dispatchQueuedManageScript(
            $this->server->fresh() ?? $this->server,
            'manage-action:set_deploy_git_identity',
            $identity->buildSetScript($deployUser, $name, $email),
            60,
            __('Deploy user Git identity saved.'),
            __('TaskRunner (SSH)').' — '.__('Git identity'),
            __('Git identity'),
        );
    }

    public function applyDefaultDeployGitIdentity(): void
    {
        $defaults = app(ServerDeployGitIdentity::class)->defaults($this->server);
        $this->git_deploy_identity_name = $defaults['name'];
        $this->git_deploy_identity_email = $defaults['email'];
        $this->saveDeployGitIdentity();
    }

    public function saveManageMetadata(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server manage settings.'));

            return;
        }

        $this->validate([
            'manage_auto_updates_interval' => ['required', 'string', 'in:'.implode(',', array_keys(config('server_manage.auto_update_intervals', [])))],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['manage_auto_updates_interval'] = $this->manage_auto_updates_interval;

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->toastSuccess(__('Manage preferences saved.'));
    }
}
