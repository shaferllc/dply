<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services\Runtimes;

use App\Modules\Queue\Contracts\WorkerRuntime;
use App\Modules\Queue\Support\WorkerHandle;
use App\Modules\Queue\Support\WorkerSpec;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * In-memory runtime: the tests' substrate, and local development's.
 *
 * Scaling logic is the part of this product that is expensive to get wrong
 * and cheap to test, but only if a test can run a hundred scale cycles
 * without a container runtime. This is that seam.
 */
class FakeWorkerRuntime implements WorkerRuntime
{
    /** @var array<string, WorkerSpec> ref => spec */
    private array $running = [];

    /** @var list<array{action: string, ref: string}> */
    private array $log = [];

    /** Next start() throws, to exercise the placement-failure path. */
    private bool $failNextStart = false;

    public function name(): string
    {
        return 'fake';
    }

    public function start(WorkerSpec $spec): WorkerHandle
    {
        if ($this->failNextStart) {
            $this->failNextStart = false;

            throw new RuntimeException('no capacity');
        }

        $ref = (string) Str::ulid();
        $this->running[$ref] = $spec;
        $this->log[] = ['action' => 'start', 'ref' => $ref];

        return new WorkerHandle($ref, $this->name());
    }

    public function stop(WorkerHandle $handle, int $graceSeconds): void
    {
        unset($this->running[$handle->ref]);
        $this->log[] = ['action' => 'stop', 'ref' => $handle->ref];
    }

    public function isAlive(WorkerHandle $handle): bool
    {
        return isset($this->running[$handle->ref]);
    }

    /** Kill a worker behind the reconciler's back, as a crash would. */
    public function killSilently(string $ref): void
    {
        unset($this->running[$ref]);
    }

    public function failNextStart(): void
    {
        $this->failNextStart = true;
    }

    public function runningCount(): int
    {
        return count($this->running);
    }

    /** @return list<array{action: string, ref: string}> */
    public function log(): array
    {
        return $this->log;
    }
}
