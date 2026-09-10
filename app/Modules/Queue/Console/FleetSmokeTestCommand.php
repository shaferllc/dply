<?php

declare(strict_types=1);

namespace App\Modules\Queue\Console;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\FleetImageBuilder;
use App\Modules\Queue\Services\FleetReconciler;
use App\Modules\Queue\Services\FleetWorkerEnvironment;
use App\Modules\Queue\Support\QueueEndpoint;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Drive the managed-worker chain end to end against a real Docker host.
 *
 * Every layer below has unit coverage, and none of it proves the thing that
 * actually matters: that a repository becomes an image, that the image starts,
 * that the container reaches the queue over the public endpoint, and that a job
 * pushed here comes back drained. Those are all seams between a mock and a real
 * daemon, which is exactly where the mocked tests stop being evidence.
 *
 * Stops at the first failure, because a later step's error is almost always
 * noise once an earlier one broke.
 */
class FleetSmokeTestCommand extends Command
{
    protected $signature = 'dply:queue:fleet-smoke
        {server : Server id or name to use as the fleet host}
        {--site= : Site id to build an image from; omit and pass --image instead}
        {--image= : Skip the build and use an image already on the host}
        {--queue=smoke : Queue name for the throwaway fleet}
        {--memory=512 : MiB per worker}
        {--wait=180 : Seconds to wait for the job to drain}
        {--keep : Leave the namespace, fleet and containers in place}';

    protected $description = 'Prove the managed queue fleet chain works against a real Docker host.';

    private ?QueueNamespace $namespace = null;

    private ?ManagedQueueFleet $fleet = null;

    public function handle(
        FleetImageBuilder $builder,
        FleetReconciler $reconciler,
        FleetWorkerEnvironment $environment,
        ExecuteRemoteTaskOnServer $remote,
        QueueStore $store,
    ): int {
        try {
            $host = $this->resolveHost();

            $this->step('1. Host is opted in as a fleet host');
            $this->assertFleetHost($host);

            $this->step('2. Docker answers on the host');
            $this->assertDocker($remote, $host);

            $this->step('3. dply has a publicly reachable endpoint');
            $this->assertEndpoint();

            $this->step('4. Namespace');
            $site = $this->resolveSite();
            $this->namespace = $this->makeNamespace($site);

            $this->step('5. Fleet');
            $this->fleet = $this->makeFleet();

            $this->step('6. Image');
            $this->resolveImage($builder);

            $this->step('7. Worker environment');
            $this->assertEnvironment($environment);

            $this->step('8. Push a job');
            $jobId = $store->push($this->namespace, (string) $this->option('queue'), $this->probePayload());
            $this->ok('job '.$jobId);

            $this->step('9. Reconcile — a worker should start');
            $this->assertWorkerStarts($reconciler);

            $this->step('10. Container is running on the host');
            $this->assertContainerRunning($remote, $host);

            $this->step('11. The job drains');
            $this->assertDrained($store, (int) $this->option('wait'));

            $this->newLine();
            $this->info('The chain works: repository → image → container → queue → drained.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->cleanUp($reconciler);
        }
    }

    private function resolveHost(): Server
    {
        $needle = (string) $this->argument('server');

        $host = Server::query()->whereKey($needle)->first()
            ?? Server::query()->where('name', $needle)->first();

        if (! $host instanceof Server) {
            throw new RuntimeException('No server matched "'.$needle.'".');
        }

        return $host;
    }

    private function assertFleetHost(Server $host): void
    {
        if (! (bool) data_get($host->meta, 'queue_fleet_host.enabled', false)) {
            throw new RuntimeException(
                $host->name.' is not opted in as a fleet host. Set meta.queue_fleet_host = '
                .'{"enabled": true, "capacity_mib": 8192} on it first.'
            );
        }

        $capacity = (int) data_get($host->meta, 'queue_fleet_host.capacity_mib', 0);

        if ($capacity < (int) $this->option('memory')) {
            throw new RuntimeException('Host capacity is '.$capacity.' MiB, below the requested worker size.');
        }

        $this->ok($host->name.' — '.$capacity.' MiB');
    }

    private function assertDocker(ExecuteRemoteTaskOnServer $remote, Server $host): void
    {
        $result = $remote->runInlineBash(
            $host,
            'fleet-smoke-docker',
            'docker version --format "{{.Server.Version}}"',
            timeoutSeconds: 60,
        );

        $version = trim($result->buffer);

        if ($result->exitCode !== 0 || $version === '') {
            throw new RuntimeException('Docker did not answer on '.$host->name.': '.Str::limit($result->buffer, 300));
        }

        $this->ok('docker '.$version);
    }

    private function assertEndpoint(): void
    {
        if (QueueEndpoint::base() === '') {
            throw new RuntimeException(
                'No publicly reachable queue endpoint. Set DPLY_QUEUE_PUBLIC_URL, or APP_URL to a routable host — '
                .'a worker container cannot resolve a .test address.'
            );
        }

        $this->ok(QueueEndpoint::base());
    }

    private function resolveSite(): ?Site
    {
        $siteId = trim((string) ($this->option('site') ?? ''));

        if ($siteId === '') {
            return null;
        }

        $site = Site::query()->find($siteId);

        if (! $site instanceof Site) {
            throw new RuntimeException('No site matched "'.$siteId.'".');
        }

        return $site;
    }

    private function makeNamespace(?Site $site): QueueNamespace
    {
        $organization = $site?->organization;
        $organization ??= Organization::query()->first();

        if (! $organization instanceof Organization) {
            throw new RuntimeException('No organization to attach a namespace to.');
        }

        $namespace = QueueNamespace::query()->create([
            'organization_id' => $organization->id,
            'site_id' => $site?->id,
            'name' => 'smoke-'.Str::lower(Str::random(6)),
            'status' => QueueNamespace::STATUS_ACTIVE,
        ]);

        $this->ok($namespace->name.' — '.QueueEndpoint::forNamespace($namespace));

        return $namespace;
    }

    private function makeFleet(): ManagedQueueFleet
    {
        $fleet = ManagedQueueFleet::query()->create([
            'namespace_id' => $this->namespace->id,
            'organization_id' => $this->namespace->organization_id,
            'queue' => (string) $this->option('queue'),
            'class' => ManagedQueueFleet::CLASS_FLEX,
            'status' => ManagedQueueFleet::STATUS_ACTIVE,
            'memory_mib' => (int) $this->option('memory'),
            'min_workers' => 0,
            'max_workers' => 1,
        ]);

        $this->ok('flex, 0–1 workers, '.$fleet->memory_mib.' MiB');

        return $fleet;
    }

    private function resolveImage(FleetImageBuilder $builder): void
    {
        $given = trim((string) ($this->option('image') ?? ''));

        if ($given !== '') {
            $this->fleet->forceFill(['image' => $given])->save();
            $this->ok('using '.$given.' (build skipped)');

            return;
        }

        if ($this->namespace->site_id === null) {
            throw new RuntimeException('Pass --site to build an image, or --image to use one already on the host.');
        }

        $this->line('   building — a first build takes minutes…');

        $tag = $builder->build($this->fleet->fresh());
        $this->fleet->forceFill(['image' => $tag])->save();
        $this->ok('built '.$tag);
    }

    /**
     * The environment is where a worker dies quietly: four queue variables alone
     * boot the app with no database and no APP_KEY, so it claims a job and
     * crashes on the first query.
     */
    private function assertEnvironment(FleetWorkerEnvironment $environment): void
    {
        $env = $environment->for($this->fleet->fresh());

        foreach (['QUEUE_CONNECTION', 'DPLY_QUEUE_URL', 'DPLY_QUEUE_KEY', 'DPLY_QUEUE_SECRET'] as $required) {
            if (($env[$required] ?? '') === '') {
                throw new RuntimeException('Worker environment is missing '.$required.'.');
            }
        }

        $this->ok(count($env).' variables');

        if ($this->namespace->site_id !== null && ($env['APP_KEY'] ?? '') === '') {
            $this->warn('   APP_KEY is not in the worker environment — jobs touching Eloquent will fail.');
        }
    }

    private function assertWorkerStarts(FleetReconciler $reconciler): void
    {
        $reconciler->reconcile($this->fleet->fresh());

        $worker = ManagedQueueWorker::query()
            ->where('fleet_id', $this->fleet->id)
            ->latest('started_at')
            ->first();

        if (! $worker instanceof ManagedQueueWorker) {
            throw new RuntimeException(
                'The reconciler started no worker. Check DPLY_QUEUE_FLEET_RUNTIME names a real runtime, not "fake".'
            );
        }

        if ($worker->state === ManagedQueueWorker::STATE_ERRORED) {
            throw new RuntimeException('The worker errored on start: '.(string) $worker->stop_reason);
        }

        $this->ok('worker '.$worker->id.' — '.$worker->state);
    }

    private function assertContainerRunning(ExecuteRemoteTaskOnServer $remote, Server $host): void
    {
        $result = $remote->runInlineBash(
            $host,
            'fleet-smoke-ps',
            sprintf(
                'docker ps --filter label=dply.fleet=%s --format "{{.Names}} {{.Status}}"',
                escapeshellarg((string) $this->fleet->id),
            ),
            timeoutSeconds: 60,
        );

        $running = trim($result->buffer);

        if ($running === '') {
            throw new RuntimeException(
                'No container is running for this fleet. `docker ps -a` on '.$host->name.' shows why it exited.'
            );
        }

        $this->ok($running);
    }

    private function assertDrained(QueueStore $store, int $seconds): void
    {
        $deadline = time() + max(10, $seconds);

        while (time() < $deadline) {
            $depth = $store->depth($this->namespace, (string) $this->option('queue'));

            if (($depth->pending + $depth->reserved) === 0) {
                $this->ok('drained');

                return;
            }

            sleep(3);
        }

        throw new RuntimeException(
            'Still queued after '.$seconds.'s. The container is running but not draining — its logs will show an '
            .'authentication or connection error against the endpoint.'
        );
    }

    /** A payload the worker may fail on harmlessly: the point is that it is CLAIMED. */
    private function probePayload(): string
    {
        return (string) json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => 'dply fleet smoke probe',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 1,
            'timeout' => 30,
            'data' => ['commandName' => 'DplyFleetSmokeProbe', 'command' => 'N;'],
        ]);
    }

    private function cleanUp(FleetReconciler $reconciler): void
    {
        if ($this->option('keep')) {
            if ($this->fleet !== null) {
                $this->newLine();
                $this->line('Kept fleet '.$this->fleet->id.' and namespace '.$this->namespace?->name.'.');
            }

            return;
        }

        try {
            if ($this->fleet !== null) {
                // Scaled to zero through the reconciler rather than deleting the
                // rows underneath running containers, which would orphan them on
                // the host with nothing left pointing at them.
                $this->fleet->forceFill([
                    'status' => ManagedQueueFleet::STATUS_PAUSED,
                    'min_workers' => 0,
                    'max_workers' => 0,
                ])->save();

                $reconciler->reconcile($this->fleet->fresh());
                $this->fleet->delete();
            }

            $this->namespace?->delete();
        } catch (Throwable $e) {
            $this->warn('Cleanup left something behind: '.$e->getMessage());
        }
    }

    private function step(string $label): void
    {
        $this->newLine();
        $this->line('<fg=cyan>'.$label.'</>');
    }

    private function ok(string $detail): void
    {
        $this->line('   <fg=green>✓</> '.$detail);
    }
}
