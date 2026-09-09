<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services\Runtimes;

use App\Models\Server;
use App\Modules\Queue\Contracts\WorkerRuntime;
use App\Modules\Queue\Support\WorkerHandle;
use App\Modules\Queue\Support\WorkerSpec;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs managed workers as Docker containers on dply-owned hosts.
 *
 * The container is the customer's app image running `queue:work` against the
 * namespace's public endpoint — the same command they would run themselves,
 * so a worker that misbehaves here misbehaves identically on their own box.
 *
 * Isolation is not a later hardening pass (ADR consequence 4): this is
 * customer code on dply's machines, so every container is capability-stripped,
 * memory-capped, pid-limited and denied privilege escalation at start. A
 * runtime that started containers first and tightened them afterwards would
 * have a window in which it did not.
 *
 * The handle is `<server-id>:<container-name>` — placement has to survive in
 * the ref itself, because stopping a container requires knowing which machine
 * to ask, and the worker row's host may be edited or cleared.
 */
class DockerWorkerRuntime implements WorkerRuntime
{
    /** Docker's own limit is 1024; below that a busy Laravel app can wedge. */
    private const PIDS_LIMIT = 512;

    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $remote,
        private readonly FleetHostAllocator $allocator,
    ) {}

    public function name(): string
    {
        return 'docker';
    }

    public function start(WorkerSpec $spec): WorkerHandle
    {
        $host = $this->allocator->allocate($spec->memoryMib);
        $container = 'dply-qw-'.Str::lower((string) Str::ulid());

        $result = $this->remote->runInlineBash(
            $host,
            'queue-fleet-start',
            $this->startScript($spec, $container),
            timeoutSeconds: 120,
        );

        if ($result->exitCode !== 0) {
            throw new RuntimeException(sprintf(
                'docker run failed on host %s (exit %s): %s',
                $host->id,
                $result->exitCode ?? 'null',
                Str::limit(trim($result->buffer), 400),
            ));
        }

        return new WorkerHandle($host->id.':'.$container, $this->name(), $host->id);
    }

    public function stop(WorkerHandle $handle, int $graceSeconds): void
    {
        [$host, $container] = $this->split($handle);

        if (! $host instanceof Server) {
            return;
        }

        // `docker stop -t` sends SIGTERM and waits: `queue:work` finishes the
        // job in hand and exits, which is exactly the graceful shutdown the
        // compute class promises. The kill only lands if it overruns.
        $this->remote->runInlineBash(
            $host,
            'queue-fleet-stop',
            sprintf(
                'docker stop -t %d %s >/dev/null 2>&1 || true; docker rm -f %s >/dev/null 2>&1 || true',
                max(1, $graceSeconds),
                escapeshellarg($container),
                escapeshellarg($container),
            ),
            // Outlive the grace period, or this reports failure for a
            // container that was shutting down exactly as asked.
            timeoutSeconds: $graceSeconds + 30,
        );
    }

    public function isAlive(WorkerHandle $handle): bool
    {
        [$host, $container] = $this->split($handle);

        if (! $host instanceof Server) {
            return false;
        }

        $result = $this->remote->runInlineBash(
            $host,
            'queue-fleet-probe',
            sprintf('docker inspect -f "{{.State.Running}}" %s 2>/dev/null || echo missing', escapeshellarg($container)),
            timeoutSeconds: 30,
        );

        // Anything that is not a clear "true" is treated as gone. The
        // reconciler replaces a worker it believes is dead, so a false
        // negative costs one container; a false positive costs a queue that
        // has stopped draining and nobody noticing.
        return $result->exitCode === 0 && str_contains($result->buffer, 'true');
    }

    /**
     * The `docker run` line, and the reasoning for every flag on it.
     */
    private function startScript(WorkerSpec $spec, string $container): string
    {
        $memory = max(128, $spec->memoryMib);

        // Matches the environment filesystem rule: 512 MiB of scratch per
        // 1 GiB of memory. tmpfs rather than disk so it cannot outlive the
        // container or be read by the next tenant on this host.
        $tmpfs = max(64, (int) round($memory / 2));

        // CPU scales with memory, as the sizing table promises. Flex is capped
        // at one vCPU; a larger pro worker gets proportionally more.
        $cpus = number_format(max(0.25, $memory / 1024), 2, '.', '');

        $env = [];
        foreach ($spec->env as $key => $value) {
            $env[] = '-e '.escapeshellarg($key.'='.$value);
        }

        $flags = [
            '-d',
            '--name '.escapeshellarg($container),
            '--label dply.fleet='.escapeshellarg($spec->fleetId),
            '--memory '.$memory.'m',
            // Without this the container may swap past its memory cap, which
            // turns a fast OOM-and-retry into an indefinitely slow worker.
            '--memory-swap '.$memory.'m',
            '--cpus '.$cpus,
            '--pids-limit '.self::PIDS_LIMIT,
            '--cap-drop ALL',
            '--security-opt no-new-privileges',
            // Bridge, never host: a worker must reach the queue endpoint and
            // the customer's own dependencies, and nothing on this machine.
            '--network bridge',
            '--tmpfs /tmp:rw,noexec,nosuid,size='.$tmpfs.'m',
            '--restart no',
            ...$env,
        ];

        $command = sprintf(
            'php artisan queue:work dply --queue=%s --timeout=%d --sleep=1',
            escapeshellarg($spec->queue),
            max(30, $spec->graceSeconds),
        );

        return sprintf(
            "docker run %s %s %s",
            implode(' ', $flags),
            escapeshellarg($spec->image),
            $command,
        );
    }

    /** @return array{0: ?Server, 1: string} */
    private function split(WorkerHandle $handle): array
    {
        if (! str_contains($handle->ref, ':')) {
            return [null, $handle->ref];
        }

        [$serverId, $container] = explode(':', $handle->ref, 2);

        return [Server::query()->find($serverId), $container];
    }
}
