<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Models\Server;
use App\Models\WorkerPool;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\ProvisionStepSnapshots;

/**
 * Compact “where is this worker?” line for the site Workers list.
 * Same phases as the install modal, without loading the full step engine.
 */
class WorkerProvisionProgress
{
    public const STEPS = 7;

    /**
     * @return array{label: string, detail: string, step: int, of: int}|null
     */
    public function for(Server $member): ?array
    {
        if ($member->status === Server::STATUS_ERROR
            || $member->poolMemberState() === WorkerPool::MEMBER_ERRORED) {
            return null;
        }

        if (! $member->isProvisioningComplete()) {
            return $this->install($member);
        }

        return match ($member->poolMemberState()) {
            WorkerPool::MEMBER_PROVISIONING,
            WorkerPool::MEMBER_REPLAYING,
            WorkerPool::MEMBER_DEPLOYING => $this->row(
                7,
                __('Deploying this site’s release'),
                __('The box is up. Installing this site and starting queue workers.'),
            ),
            WorkerPool::MEMBER_DRAINING => $this->row(
                7,
                __('Draining this worker'),
                __('Stopping queue workers before this box is removed.'),
            ),
            default => null,
        };
    }

    /**
     * @return array{label: string, detail: string, step: int, of: int}
     */
    private function install(Server $member): array
    {
        $provider = $member->provider->label();
        $ip = trim((string) ($member->ip_address ?? ''));
        $fromImage = filled(data_get($member->meta, 'boot_image_id'));

        if ($member->setup_status === Server::SETUP_STATUS_FAILED) {
            return $this->row(
                5,
                __('Setup failed'),
                __('The setup script stopped before the box was ready.'),
            );
        }

        if ($member->status === Server::STATUS_PENDING) {
            return $this->row(
                1,
                __('Queued with :provider', ['provider' => $provider]),
                $fromImage
                    ? __('This worker boots from a saved image of the stack.')
                    : __('Waiting for the provider to start creating the VM.'),
            );
        }

        if ($member->status === Server::STATUS_PROVISIONING && $ip === '') {
            return $this->row(
                2,
                __('Creating the VM'),
                $fromImage
                    ? __('Launching from the saved image — setup will skip packages already installed.')
                    : __('Waiting for :provider to finish building the VM.', ['provider' => $provider]),
            );
        }

        if ($member->status === Server::STATUS_PROVISIONING
            || ($member->status === Server::STATUS_READY && $member->setup_status !== Server::SETUP_STATUS_RUNNING)) {
            return $this->row(
                $ip !== '' ? 4 : 3,
                $ip !== '' ? __('Waiting for SSH') : __('Waiting for a public IP'),
                $ip !== ''
                    ? __('The VM is up at :ip — connecting over SSH.', ['ip' => $ip])
                    : __('The VM exists. Waiting for a public IP.'),
            );
        }

        if ($member->status === Server::STATUS_READY && $member->setup_status === Server::SETUP_STATUS_RUNNING) {
            $setup = $this->currentSetupLabel($member);

            return $this->row(
                5,
                $setup ?? __('Running server setup'),
                $setup !== null
                    ? __('Installing the stack on the box.')
                    : __('Applying packages and the worker role.'),
            );
        }

        return $this->row(
            6,
            __('Finishing install'),
            __('The VM is almost ready. Then this site’s release deploys.'),
        );
    }

    /**
     * @return array{label: string, detail: string, step: int, of: int}
     */
    private function row(int $step, string $label, string $detail): array
    {
        return [
            'label' => $label,
            'detail' => $detail,
            'step' => $step,
            'of' => self::STEPS,
        ];
    }

    private function currentSetupLabel(Server $member): ?string
    {
        $taskId = trim((string) data_get($member->meta, 'provision_task_id'));
        if ($taskId === '') {
            return null;
        }

        $task = Task::query()->find($taskId);
        if (! $task instanceof Task || ! is_string($task->output) || trim($task->output) === '') {
            return null;
        }

        $labels = ProvisionStepSnapshots::extractLabels($task->output);
        if ($labels === []) {
            return null;
        }

        return $labels[array_key_last($labels)];
    }
}
