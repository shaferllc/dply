<?php

declare(strict_types=1);

namespace App\Modules\Queue;

use App\Modules\Queue\Console\FlushQueueUsageCommand;
use App\Modules\Queue\Contracts\QueueStore;
use App\Modules\Queue\Livewire\QueueNamespaceShow;
use App\Modules\Queue\Livewire\Queues;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Observers\QueueNamespaceBillingObserver;
use App\Modules\Queue\Services\PostgresQueueStore;
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                FlushQueueUsageCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Gate::policy(QueueNamespace::class, QueueNamespacePolicy::class);

        // Registered here rather than in AppServiceProvider (where Realtime's
        // equivalent still lives) so the module carries its own wiring.
        QueueNamespace::observe(QueueNamespaceBillingObserver::class);

        Livewire::component('queues', Queues::class);
        Livewire::component('queue-namespace-show', QueueNamespaceShow::class);
    }
}
