<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\FakeCloudProvision;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait InspectsProvisionState
{


    protected function shouldPoll(): bool
    {
        return ! $this->shouldRedirectToServerOverview();
    }

    /**
     * Copy/paste hints for local SSH dev (docker-compose.ssh-dev, fake cloud). Not used in production UI.
     *
     * @return array{
     *     ssh:string,
     *     docker_exec:string,
     *     web_terminal_url:?string,
     *     fake_cloud_enabled:bool,
     *     is_fake_server:bool
     * }|null
     */
    protected function localDevShellHints(): ?array
    {
        // Two gates per the local-dev pattern: only render when the
        // operator is actually running fake-cloud locally. Disabling
        // DPLY_FAKE_CLOUD_PROVISION (or moving to a non-local env)
        // hides the local-docker shell hints entirely so production
        // operators don't see "exec into the dply-ssh-dev container"
        // hints that would mislead them.
        if (! app()->environment('local') || ! FakeCloudProvision::enabled()) {
            return null;
        }

        $ip = trim((string) ($this->server->ip_address ?? ''));
        $port = (int) ($this->server->ssh_port ?: 22);
        $user = trim((string) ($this->server->ssh_user ?? ''));
        if ($user === '') {
            $user = 'root';
        }

        $ssh = $ip !== ''
            ? ($port === 22 ? "ssh {$user}@{$ip}" : "ssh -p {$port} {$user}@{$ip}")
            : '';

        $container = (string) config('server_provision.local_dev_ssh_compose_container', 'dply-ssh-dev');
        $dockerExec = "docker exec -it {$container} /bin/bash";

        $webUrl = config('server_provision.local_dev_web_terminal_url');
        $webTerminalUrl = is_string($webUrl) && $webUrl !== '' ? $webUrl : null;

        return [
            'ssh' => $ssh,
            'docker_exec' => $dockerExec,
            'web_terminal_url' => $webTerminalUrl,
            'fake_cloud_enabled' => FakeCloudProvision::enabled(),
            'is_fake_server' => FakeCloudProvision::isFakeServer($this->server),
        ];
    }

    protected function shouldRedirectToServerOverview(): bool
    {
        return $this->server->status === Server::STATUS_READY
            && $this->server->setup_status === Server::SETUP_STATUS_DONE;
    }

    protected function provisionTask(): ?Task
    {
        $taskId = (string) ($this->server->meta['provision_task_id'] ?? '');
        if ($taskId === '') {
            return null;
        }

        return Task::query()->find($taskId);
    }

    protected function provisionRun(): ?ServerProvisionRun
    {
        $runId = (string) ($this->server->meta['provision_run_id'] ?? '');
        if ($runId !== '') {
            return ServerProvisionRun::query()->with('artifacts')->find($runId);
        }

        $task = $this->provisionTask();

        return ServerProvisionRun::query()
            ->with('artifacts')
            ->when($task, fn ($query) => $query->where('task_id', $task->id))
            ->where('server_id', $this->server->id)
            ->latest('created_at')
            ->first();
    }

    protected function activeProvisionTask(): ?Task
    {
        $task = $this->provisionTask();

        if (! $task || ! $task->status->isActive()) {
            return null;
        }

        return $task;
    }

    protected function canCancelProvision(?Task $task): bool
    {
        return $task?->status->isActive() === true
            || in_array($this->server->status, [Server::STATUS_PENDING, Server::STATUS_PROVISIONING], true)
            || $this->server->setup_status === Server::SETUP_STATUS_RUNNING;
    }
}
