<?php

declare(strict_types=1);

namespace App\Services\RemoteCli;

use App\Models\RemoteCliRun;

/**
 * Return value of {@see RemoteCli::run()}.
 *
 * For a sync run, the result is fully populated and the underlying
 * {@see RemoteCliRun} row is in a terminal status. For an async run
 * (added in PR 2), only `runId` is meaningful inline; callers tail
 * the run via the run id.
 */
final class RemoteCliResult
{
    public function __construct(
        public readonly RemoteCliRun $run,
    ) {}

    public function status(): string
    {
        return $this->run->status;
    }

    public function isQueued(): bool
    {
        return $this->run->status === RemoteCliRun::STATUS_QUEUED;
    }

    public function isCompleted(): bool
    {
        return $this->run->status === RemoteCliRun::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->run->status === RemoteCliRun::STATUS_FAILED;
    }

    public function exitCode(): ?int
    {
        return $this->run->exit_code;
    }

    public function stdout(): string
    {
        return (string) $this->run->stdout;
    }

    public function stderr(): string
    {
        return (string) $this->run->stderr;
    }
}
