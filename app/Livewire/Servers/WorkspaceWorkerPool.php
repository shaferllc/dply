<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerPoolManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Server workspace page for cloning + scaling a worker server as a Worker Pool.
 * Same-region v1: create a pool from a worker host, set a desired member count,
 * promote a member, or remove (drain + destroy) a replica.
 *
 * See doc/specs/worker-pools/02-specification.md.
 */
#[Layout('layouts.app')]
class WorkspaceWorkerPool extends Component
{
    use InteractsWithServerWorkspace;

    public string $pool_name = '';

    public int $desired_count = 1;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        $pool = $this->pool();
        if ($pool) {
            $this->desired_count = $pool->desired_count;
            $this->pool_name = $pool->name;
        } else {
            $this->pool_name = $server->name.' pool';
        }
    }

    public function pool(): ?WorkerPool
    {
        $id = $this->server->worker_pool_id;

        return $id ? WorkerPool::query()->with('servers')->find($id) : null;
    }

    public function createPool(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        if (! $this->server->isWorkerHost()) {
            $this->toastError(__('Only worker servers can start a worker pool.'));

            return;
        }

        try {
            $pool = $manager->createPool(auth()->user(), $this->server->fresh(), trim($this->pool_name));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->desired_count = $pool->desired_count;
        $this->toastSuccess(__('Worker pool created. This server is the primary.'));
    }

    public function scale(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool to scale.'));

            return;
        }

        try {
            $manager->setDesiredCount($pool, (int) $this->desired_count);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Scaling to :n worker(s). Provisioning runs in the background.', ['n' => (int) $this->desired_count]));
    }

    public function promote(string $serverId, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        $member = $pool?->servers->firstWhere('id', $serverId);
        if (! $pool || ! $member) {
            $this->toastError(__('Member not found.'));

            return;
        }

        try {
            $manager->promote($pool, $member);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->toastSuccess(__(':name is now the pool primary.', ['name' => $member->name]));
    }

    public function removeMember(string $serverId, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        $member = $pool?->servers->firstWhere('id', $serverId);
        if (! $pool || ! $member) {
            $this->toastError(__('Member not found.'));

            return;
        }

        try {
            $manager->removeMember($pool, $member);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Draining :name, then it will be destroyed.', ['name' => $member->name]));
    }

    public function render(): View
    {
        $pool = $this->pool();
        $members = $pool
            ? $pool->servers()->orderByRaw("CASE WHEN pool_role = 'primary' THEN 0 ELSE 1 END")->orderBy('created_at')->get()
            : collect();

        return view('livewire.servers.workspace-worker-pool', [
            'server' => $this->server,
            'pool' => $pool,
            'members' => $members,
        ]);
    }
}
