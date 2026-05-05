<?php

declare(strict_types=1);

namespace App\Support\Debug;

use Illuminate\Support\Carbon;

/**
 * Read-only DTO representing a single TaskRunner / SSH / process-run row in
 * the unified debug-panel feed. Sources: task_runner_tasks, server_manage_actions,
 * remote_cli_runs.
 */
final class ActivityRow
{
    public function __construct(
        public readonly string $source,
        public readonly string $id,
        public readonly string $label,
        public readonly string $commandPreview,
        public readonly string $status,
        public readonly ?int $exitCode,
        public readonly ?int $durationSeconds,
        public readonly ?Carbon $startedAt,
        public readonly ?Carbon $finishedAt,
        public readonly ?Carbon $createdAt,
        public readonly ?string $serverId,
        public readonly ?string $siteId,
        public readonly ?string $actorUserId,
        public readonly ?string $errorMessage,
    ) {}

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'pending', 'running'], true);
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'timeout', 'connection_failed', 'upload_failed', 'cancelled'], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['finished', 'completed'], true);
    }
}
