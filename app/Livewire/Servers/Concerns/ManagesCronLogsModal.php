<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCronLogsModal
{
    public ?string $viewing_logs_job_id = null;

    public function openLogsModal(string $jobId): void
    {
        $this->viewing_logs_job_id = $jobId;
    }

    public function closeLogsModal(): void
    {
        $this->viewing_logs_job_id = null;
    }
}
