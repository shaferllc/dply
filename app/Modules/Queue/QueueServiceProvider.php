<?php

declare(strict_types=1);

namespace App\Modules\Queue;

use App\Modules\Queue\Console\FlushQueueUsageCommand;
use App\Modules\Queue\Console\MeterQueueUsageCommand;
use App\Modules\Queue\Console\QueueDoctorCommand;
use App\Modules\Queue\Console\QueueFleetTickCommand;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Contracts\WorkerRuntime;
use App\Modules\Queue\Livewire\QueueNamespaceShow;
use App\Modules\Queue\Livewire\Queues;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Observers\QueueNamespaceBillingObserver;
use App\Modules\Queue\Services\PostgresQueueStore;
use App\Modules\Queue\Services\Runtimes\DockerWorkerRuntime;
use App\Modules\Queue\Services\Runtimes\FakeWorkerRuntime;
use App\Policies\QueueNamespacePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * dply Queue module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The managed job queue: an org-owned namespace, credentials, and — from M2
 * onward — the job store and its HTTP surface. See docs/adr/dply-queue.md.
 *
 * Not to be confused with Laravel's own queue configuration. This module is
 * the queue dply *sells*; `config/queue.php` and Horizon are the queue dply
 * *runs*. They share no code and no storage.
 */
class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Postgres is the only store today. A Redis one may follow, but its
        // value would be Horizon compatibility via a real RESP endpoint, not
        // throughput — a much larger job than a second binding here.
        $this->app->bind(QueueStore::class, PostgresQueueStore::class);

        // The substrate managed workers run on. Fake is the default because
        // the alternative default is "start containers on whatever machine
        // booted this container" — a real runtime has to be chosen, never
        // arrived at. See docs/adr/managed-queue-workers.md, decision 3.
        $this->app->bind(WorkerRuntime::class, function ($app) {
            $runtimes = [
                'fake' => FakeWorkerRuntime::class,
                'docker' => DockerWorkerRuntime::class,
            ];

            $configured = (string) config('queue_service.fleets.runtime', 'fake');

            return $app->make($runtimes[$configured] ?? FakeWorkerRuntime::class);
        });

        $this->app->singleton(FakeWorkerRuntime::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                // Per-namespace observational rollup, feeding the dashboard
                // sparkline. Nothing is invoiced from it — a namespace is priced
                // by capacity tier (docs/adr/managed-services-tier.md, dec. 6).
                FlushQueueUsageCommand::class,
                // Per-org metered rollup from the original decision-9 design.
                // Retained and still running, but no longer a billing input; see
                // the note on QueueUsageMeter.
                MeterQueueUsageCommand::class,
                // Readiness readout — kill switch, endpoint, store isolation,
                // schema and table health in one green/red pass.
                QueueDoctorCommand::class,
                // Sizes managed worker fleets against real queue pressure.
                QueueFleetTickCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Gate::policy(QueueNamespace::class, QueueNamespacePolicy::class);

        // Registered here rather than in AppServiceProvider (where Realtime's
        // equivalent still lives) so the module carries its own wiring.
        QueueNamespace::observe(QueueNamespaceBillingObserver::class);

        // Moving a component into a module stops Livewire's auto-discovery, so
        // its alias is re-registered here (tests/Feature/LivewireAliasGuardTest).
        // Both alias sets resolve to the same components: the pages moved from
        // the org-settings shell to the Services row, and the older
        // `organizations.*` names stay registered so anything still referencing
        // them keeps resolving.
        Livewire::component('queues', Queues::class);
        Livewire::component('queue-namespace-show', QueueNamespaceShow::class);
        Livewire::component('organizations.queues', Queues::class);
        Livewire::component('organizations.queue-namespace-show', QueueNamespaceShow::class);
    }
}
