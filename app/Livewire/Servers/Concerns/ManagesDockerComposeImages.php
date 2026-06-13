<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Support\Servers\ServerDockerRemoteInspector;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDockerComposeImages
{


    public function confirmDockerComposeAction(string $actionKey, string $project, string $config): void
    {
        $allowed = [
            'docker_compose_up',
            'docker_compose_down',
            'docker_compose_restart',
        ];

        if (! in_array($actionKey, $allowed, true)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $inspector = app(ServerDockerRemoteInspector::class);
        $config = $inspector->primaryComposeConfigFile($config);

        if (! $inspector->isValidComposeProjectName($project) || ! $inspector->isValidComposeConfigPath($config)) {
            $this->toastError(__('Invalid compose project.'));

            return;
        }

        $def = config('server_manage.service_actions.'.$actionKey, []);
        if (! is_array($def)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $confirm = str_replace(':project', $project, (string) ($def['confirm'] ?? ''));

        $this->openConfirmActionModal(
            'runAllowlistedManageAction',
            [$actionKey, null, null, null, $project, $config],
            (string) ($def['label'] ?? $actionKey),
            $confirm,
            (string) ($def['label'] ?? $actionKey),
            $actionKey === 'docker_compose_down',
        );
    }

    public function openComposeLogs(string $project, string $config): void
    {
        $inspector = app(ServerDockerRemoteInspector::class);
        $config = $inspector->primaryComposeConfigFile($config);

        if (! $inspector->isValidComposeProjectName($project) || ! $inspector->isValidComposeConfigPath($config)) {
            $this->toastError(__('Invalid compose project.'));

            return;
        }

        $this->composeLogsModalProject = $project;
        $this->composeLogsModalConfig = $config;
        $this->composeLogsModalContent = '';
        $this->composeLogsModalError = null;
        $this->composeLogsModalLoading = true;

        try {
            $result = $inspector->composeProjectLogs($this->server, $project, $config);
            $this->composeLogsModalContent = $result['logs'];
            $this->composeLogsModalError = $result['error'];
        } catch (\Throwable $e) {
            $this->composeLogsModalError = $e->getMessage();
        } finally {
            $this->composeLogsModalLoading = false;
        }
    }

    public function closeComposeLogsModal(): void
    {
        $this->composeLogsModalProject = null;
        $this->composeLogsModalConfig = null;
        $this->composeLogsModalContent = '';
        $this->composeLogsModalError = null;
        $this->composeLogsModalLoading = false;
    }

    public function confirmDockerImageAction(string $actionKey, string $imageRef): void
    {
        $allowed = ['docker_image_rm'];

        if (! in_array($actionKey, $allowed, true)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openDockerManageAction($actionKey, [$actionKey, null, $imageRef]);
    }

    public function confirmDockerInstall(): void
    {
        $this->openDockerManageAction('install_docker', ['install_docker']);
    }

    public function confirmDockerUpgrade(): void
    {
        $this->openDockerManageAction('repair_docker', ['repair_docker']);
    }

    public function confirmDockerImagePull(): void
    {
        $ref = trim($this->pullImageInput);
        if ($ref === '') {
            $this->toastError(__('Enter an image reference (e.g. nginx:alpine).'));

            return;
        }

        if (! app(ServerDockerRemoteInspector::class)->isValidImageRef($ref)) {
            $this->toastError(__('Invalid image reference.'));

            return;
        }

        $this->openDockerManageAction('docker_image_pull', ['docker_image_pull', null, $ref]);
    }

    public function confirmDockerImagePrune(): void
    {
        $this->openDockerManageAction('docker_image_prune', ['docker_image_prune']);
    }

    public function confirmDockerVolumePrune(): void
    {
        $this->openDockerManageAction('docker_volume_prune', ['docker_volume_prune']);
    }

    public function confirmDockerSystemPrune(): void
    {
        $this->openDockerManageAction('docker_system_prune', ['docker_system_prune']);
    }

    /**
     * @param  list<string|int|null>  $actionArgs
     */
    private function openDockerManageAction(string $key, array $actionArgs): void
    {
        $service = config('server_manage.service_actions', []);
        $def = $service[$key] ?? null;
        if (! is_array($def)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openConfirmActionModal(
            'runAllowlistedManageAction',
            $actionArgs,
            (string) ($def['label'] ?? $key),
            (string) ($def['confirm'] ?? ''),
            (string) ($def['label'] ?? $key),
            false,
        );
    }
}
