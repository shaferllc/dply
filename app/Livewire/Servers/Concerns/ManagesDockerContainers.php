<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\SshConnectionFactory;
use App\Support\Servers\DockerContainerShellSupport;
use App\Support\Servers\ServerDockerRemoteInspector;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDockerContainers
{


    public function confirmDockerContainerAction(string $actionKey, string $containerId): void
    {
        $allowed = [
            'docker_container_start',
            'docker_container_stop',
            'docker_container_restart',
            'docker_container_rm',
        ];

        if (! in_array($actionKey, $allowed, true)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openDockerManageAction($actionKey, [$actionKey, $containerId]);
    }

    public function openContainerExec(string $containerId, string $containerName): void
    {
        if (! app(ServerDockerRemoteInspector::class)->isValidContainerRef($containerId)) {
            $this->toastError(__('Invalid container.'));

            return;
        }

        $this->execModalContainerId = $containerId;
        $this->execModalContainerName = $containerName;
        $this->execModalCommand = '';
    }

    public function closeContainerExecModal(): void
    {
        $this->execModalContainerId = null;
        $this->execModalContainerName = null;
        $this->execModalCommand = '';
    }

    public function submitContainerExec(): void
    {
        if ($this->execModalContainerId === null) {
            return;
        }

        $command = trim($this->execModalCommand);
        $inspector = app(ServerDockerRemoteInspector::class);

        if (! $inspector->isValidExecCommand($command)) {
            $this->toastError(__('Enter a single-line command (max 4000 characters).'));

            return;
        }

        $def = config('server_manage.service_actions.docker_container_exec', []);
        $confirm = __('Run `:command` inside container `:name`? Output appears in the console banner.', [
            'command' => $command,
            'name' => $this->execModalContainerName,
        ]);

        $this->openConfirmActionModal(
            'runAllowlistedManageAction',
            ['docker_container_exec', $this->execModalContainerId, null, $command],
            (string) ($def['label'] ?? __('Run command in container')),
            $confirm,
            (string) ($def['label'] ?? __('Run command')),
            false,
        );

        $this->closeContainerExecModal();
    }

    public function openContainerShell(string $containerId, string $containerName): void
    {
        if (! app(ServerDockerRemoteInspector::class)->isValidContainerRef($containerId)) {
            $this->toastError(__('Invalid container.'));

            return;
        }

        $this->shellModalContainerId = $containerId;
        $this->shellModalContainerName = $containerName;
        $this->shellModalCommand = '';
        $this->shellModalError = null;
        $this->shellModalRunning = false;
        $this->shellModalHistory = [];
    }

    public function closeContainerShell(): void
    {
        $this->shellModalContainerId = null;
        $this->shellModalContainerName = null;
        $this->shellModalCommand = '';
        $this->shellModalError = null;
        $this->shellModalRunning = false;
        $this->shellModalHistory = [];
    }

    public function clearContainerShellHistory(): void
    {
        $this->shellModalHistory = [];
        $this->shellModalError = null;
    }

    public function insertContainerShellCommand(string $command): void
    {
        $this->shellModalCommand = $command;
    }

    public function runContainerShellQuickAction(int $index): void
    {
        $actions = DockerContainerShellSupport::quickActions();
        if (! isset($actions[$index])) {
            return;
        }

        $this->shellModalCommand = $actions[$index]['cmd'];
        $this->runContainerShellCommand();
    }

    public function runContainerShellCommand(): void
    {
        if ($this->shellModalContainerId === null) {
            return;
        }

        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->shellModalError = __('Deployers cannot run shell commands on servers.');

            return;
        }

        if ($this->shellModalRunning) {
            $this->shellModalError = __('A command is already running. Wait for it to complete.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->shellModalError = __('Provisioning and SSH must be ready before running commands.');

            return;
        }

        $cmd = trim($this->shellModalCommand);
        $inspector = app(ServerDockerRemoteInspector::class);

        if (! $inspector->isValidExecCommand($cmd)) {
            $this->shellModalError = __('Enter a single-line command (max 4000 characters).');

            return;
        }

        $this->shellModalError = null;
        $this->shellModalRunning = true;
        $startedAt = microtime(true);

        try {
            $ssh = app(SshConnectionFactory::class)->forServer($this->server);
            $remote = DockerContainerShellSupport::remoteExecCommand($this->shellModalContainerId, $cmd);
            [$out, $exit] = $ssh->execWithCallbackAndExit(
                $remote,
                static fn (string $chunk) => null,
                120,
            );

            $this->shellModalHistory[] = [
                'cmd' => $cmd,
                'out' => Str::limit($out, 16000, "\n… (output truncated)"),
                'exit' => $exit,
                'error' => null,
            ];

            $this->logContainerShellAudit($cmd, $exit, null, $startedAt);
        } catch (\Throwable $e) {
            $message = Str::limit($e->getMessage(), 200);
            $this->shellModalHistory[] = [
                'cmd' => $cmd,
                'out' => '',
                'exit' => null,
                'error' => $message,
            ];
            $this->logContainerShellAudit($cmd, null, $message, $startedAt);
        } finally {
            $this->shellModalRunning = false;
        }

        if (count($this->shellModalHistory) > 30) {
            $this->shellModalHistory = array_slice($this->shellModalHistory, -30);
        }

        $this->shellModalCommand = '';
        $this->dispatch('scroll-console-bottom');
    }

    protected function logContainerShellAudit(string $command, ?int $exit, ?string $error, float $startedAt): void
    {
        $organization = $this->server->organization;
        if ($organization === null) {
            return;
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $status = $error !== null ? 'failed' : ($exit === 0 ? 'success' : 'nonzero_exit');

        audit_log(
            $organization,
            auth()->user(),
            'server.docker.container_shell_command',
            $this->server,
            null,
            [
                'container_id' => $this->shellModalContainerId,
                'container_name' => $this->shellModalContainerName,
                'command' => Str::limit($command, 1000),
                'exit_code' => $exit,
                'status' => $status,
                'duration_ms' => $duration,
                'error' => $error !== null ? Str::limit($error, 500) : null,
            ],
        );
    }

    public function openContainerLogs(string $containerId, string $containerName): void
    {
        if (! app(ServerDockerRemoteInspector::class)->isValidContainerRef($containerId)) {
            $this->toastError(__('Invalid container.'));

            return;
        }

        $this->logsModalContainerId = $containerId;
        $this->logsModalContainerName = $containerName;
        $this->logsModalContent = '';
        $this->logsModalError = null;
        $this->logsModalLoading = true;

        try {
            $result = app(ServerDockerRemoteInspector::class)->containerLogs($this->server, $containerId);
            $this->logsModalContent = $result['logs'];
            $this->logsModalError = $result['error'];
        } catch (\Throwable $e) {
            $this->logsModalError = $e->getMessage();
        } finally {
            $this->logsModalLoading = false;
        }
    }

    public function closeContainerLogsModal(): void
    {
        $this->logsModalContainerId = null;
        $this->logsModalContainerName = null;
        $this->logsModalContent = '';
        $this->logsModalError = null;
        $this->logsModalLoading = false;
    }

    public function openContainerInspect(string $containerId, string $containerName): void
    {
        if (! app(ServerDockerRemoteInspector::class)->isValidContainerRef($containerId)) {
            $this->toastError(__('Invalid container.'));

            return;
        }

        $this->inspectModalContainerId = $containerId;
        $this->inspectModalContainerName = $containerName;
        $this->inspectModalContent = '';
        $this->inspectModalError = null;
        $this->inspectModalLoading = true;

        try {
            $result = app(ServerDockerRemoteInspector::class)->containerInspect($this->server, $containerId);
            $this->inspectModalContent = $result['inspect'];
            $this->inspectModalError = $result['error'];
        } catch (\Throwable $e) {
            $this->inspectModalError = $e->getMessage();
        } finally {
            $this->inspectModalLoading = false;
        }
    }

    public function closeContainerInspectModal(): void
    {
        $this->inspectModalContainerId = null;
        $this->inspectModalContainerName = null;
        $this->inspectModalContent = '';
        $this->inspectModalError = null;
        $this->inspectModalLoading = false;
    }
}
