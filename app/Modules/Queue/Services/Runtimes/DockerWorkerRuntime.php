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

    /** Heredoc terminator for the env file; must not appear in a value. */
    private const ENV_EOF = 'DPLY_ENV_EOF';

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

        // Passed by file, never as `-e` arguments. The worker environment is the
        // site's whole .env — APP_KEY, database password, every third-party
        // token — and a `docker run` argument list is readable with `ps` by
        // anything else on this host, which on a fleet host is another
        // customer's worker.
        $envFile = '/tmp/'.$container.'.env';

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
            '--env-file '.escapeshellarg($envFile),
        ];

        $command = sprintf(
            'php artisan queue:work dply --queue=%s --timeout=%d --sleep=1',
            escapeshellarg($spec->queue),
            max(30, $spec->graceSeconds),
        );

        return implode("\n", array_filter([
            $this->fetchScript($spec),
            $this->writeEnvFileScript($spec, $envFile),
            // The run's status is captured so the env file is removed even when
            // the container fails to start: `runInlineBash` runs under `set -e`,
            // and a secret-bearing file left on a shared host is the one piece
            // of this that outlives the failure.
            sprintf(
                'docker run %s %s %s && DPLY_RUN_RC=0 || DPLY_RUN_RC=$?',
                implode(' ', $flags),
                escapeshellarg($spec->image),
                $command,
            ),
            sprintf('rm -f %s', escapeshellarg($envFile)),
            'exit "$DPLY_RUN_RC"',
        ]));
    }

    /**
     * Write the environment to a private file on the host.
     *
     * A quoted heredoc so nothing in a customer's values is expanded by the
     * shell on the way in — a password containing `$(` is a password, not a
     * command. Written 0600 before any content reaches it.
     *
     * Docker's env-file format is one `KEY=value` per line and has no escaping,
     * so a value containing a newline cannot be represented; those are dropped
     * rather than allowed to truncate the file and silently redefine whatever
     * key follows.
     */
    private function writeEnvFileScript(WorkerSpec $spec, string $envFile): string
    {
        $lines = [];

        foreach ($spec->env as $key => $value) {
            $key = (string) $key;
            $value = (string) $value;

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                continue;
            }

            // Cannot collide with the heredoc terminator below.
            if (trim($value) === self::ENV_EOF) {
                continue;
            }

            $lines[] = $key.'='.$value;
        }

        $path = escapeshellarg($envFile);

        return implode("\n", [
            sprintf('rm -f %s && install -m 600 /dev/null %s', $path, $path),
            sprintf('cat > %s <<\'%s\'', $path, self::ENV_EOF),
            ...$lines,
            self::ENV_EOF,
        ]);
    }

    /**
     * Refresh the image if we can, then require it to be present.
     *
     * "Pull, and fail the start if the pull failed" is the obvious shape and the
     * wrong one twice over. It makes a registry mandatory — so an image built on
     * the fleet host itself can never run, which is exactly the setup worth
     * proving the stack with before buying registry infrastructure. And it turns
     * a momentary registry outage into a failed scale-up on a host that already
     * has the image sitting in its daemon.
     *
     * So the pull is best-effort and presence is the gate. A tag that moves
     * (`:latest`) still refreshes on every start; a tag that cannot be pulled
     * runs if it is already here and fails cleanly if it is not.
     */
    private function fetchScript(WorkerSpec $spec): string
    {
        $image = escapeshellarg($spec->image);

        $lines = [];

        if (($spec->registryUsername ?? '') !== '' && ($spec->registryPassword ?? '') !== '') {
            $registry = self::registryHostFor($spec->image);
            $logoutTarget = $registry === '' ? '' : escapeshellarg($registry);

            $lines = [
                // `set +x` so a traced script cannot echo the credential, and
                // --password-stdin so it never reaches the process table, where
                // every other tenant on this host could read it out of `ps`.
                'set +x',
                'DPLY_REGISTRY_PASSWORD='.escapeshellarg((string) $spec->registryPassword),
                sprintf(
                    'printf %%s "$DPLY_REGISTRY_PASSWORD" | docker login %s-u %s --password-stdin >/dev/null || true',
                    $registry === '' ? '' : escapeshellarg($registry).' ',
                    escapeshellarg((string) $spec->registryUsername),
                ),
                'unset DPLY_REGISTRY_PASSWORD',
                sprintf('docker pull %s || true', $image),
                // Unconditional: the login must not outlive this start, or one
                // customer's registry session is left for the next tenant on
                // this host to inherit.
                sprintf('docker logout %s >/dev/null 2>&1 || true', $logoutTarget),
            ];
        } else {
            $lines[] = sprintf('docker pull %s || true', $image);
        }

        $lines[] = sprintf(
            'docker image inspect %s >/dev/null 2>&1 || { echo "image not available: %s"; exit 1; }',
            $image,
            $spec->image,
        );

        return implode("\n", $lines);
    }

    /**
     * The registry `docker login` needs for this image reference.
     *
     * Docker's own rule: the first path segment is a registry host only when it
     * looks like one — it contains a dot or a port, or is literally localhost.
     * `acme/app` is Docker Hub with an org named acme, not a host named acme.
     * Returning '' means Docker Hub, which is what `docker login` defaults to.
     */
    public static function registryHostFor(string $image): string
    {
        $first = explode('/', trim($image), 2)[0];

        if (! str_contains($image, '/')) {
            return '';
        }

        return str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost'
            ? $first
            : '';
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
