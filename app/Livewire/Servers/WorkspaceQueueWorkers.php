<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Friendlier lens on {@see SupervisorProgram}: just the queue-flavored entries
 * (Laravel queue/Horizon, Sidekiq, Solid Queue, Celery, Octane/Reverb, etc.).
 *
 * The full daemon CRUD still lives on {@see WorkspaceDaemons} — this page lists
 * existing workers and surfaces preset shortcuts that deep-link there with the
 * preset prefilled (`?preset=` is already handled by WorkspaceDaemons::mount).
 */
#[Layout('layouts.app')]
class WorkspaceQueueWorkers extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** Program types that count as "queue workers" for this page. */
    public const QUEUE_TYPES = [
        'queue',
        'horizon',
        'sidekiq',
        'solid-queue',
        'celery',
        'bullmq',
        'reverb',
        'octane',
    ];

    /**
     * Preset cards shown when the operator wants to add a new worker.
     * Each one deep-links to {@see WorkspaceDaemons} with the preset prefilled,
     * so the wizard there does the actual work — no duplicated form here.
     *
     * @var list<array{key: string, label: string, description: string, framework: string}>
     */
    public const PRESETS = [
        ['key' => 'laravel-queue', 'label' => 'Laravel queue', 'description' => 'php artisan queue:work with the structured builder.', 'framework' => 'laravel'],
        ['key' => 'laravel-horizon', 'label' => 'Laravel Horizon', 'description' => 'php artisan horizon — Redis-backed dashboard + workers.', 'framework' => 'laravel'],
        ['key' => 'laravel-octane', 'label' => 'Laravel Octane', 'description' => 'High-performance app server (Swoole / RoadRunner / FrankenPHP).', 'framework' => 'laravel'],
        ['key' => 'reverb', 'label' => 'Laravel Reverb', 'description' => 'php artisan reverb:start — first-party WebSocket server.', 'framework' => 'laravel'],
        ['key' => 'sidekiq', 'label' => 'Sidekiq', 'description' => 'bundle exec sidekiq — Redis-backed Ruby/Rails worker.', 'framework' => 'rails'],
        ['key' => 'nodejs', 'label' => 'Node.js worker', 'description' => 'Generic node server.js — bring your own command.', 'framework' => 'node'],
    ];

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function restartWorker(SupervisorProvisioner $provisioner, string $programId): void
    {
        $this->authorize('update', $this->server);

        $program = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereIn('program_type', self::QUEUE_TYPES)
            ->whereKey($programId)
            ->firstOrFail();

        try {
            $provisioner->restartProgramGroup($this->server->fresh(), $program->id);
            $this->toastSuccess(__('Restart sent to :slug.', ['slug' => $program->slug]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function render(): View
    {
        $this->server->refresh();

        $programs = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereIn('program_type', self::QUEUE_TYPES)
            ->orderBy('slug')
            ->get();

        return view('livewire.servers.workspace-queue-workers', [
            'opsReady' => $this->serverOpsReady(),
            'programs' => $programs,
            'presets' => self::PRESETS,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
