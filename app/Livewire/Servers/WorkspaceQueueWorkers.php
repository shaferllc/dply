<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\ChecksSupervisorInstallStatus;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
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
    use ChecksSupervisorInstallStatus;
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
        ['key' => 'solid-queue', 'label' => 'Solid Queue', 'description' => 'bin/jobs — Rails 8 default database-backed worker.', 'framework' => 'rails'],
        ['key' => 'action-cable', 'label' => 'Action Cable', 'description' => 'Standalone Puma serving cable/config.ru for Rails websockets.', 'framework' => 'rails'],
        ['key' => 'nodejs', 'label' => 'Node.js worker', 'description' => 'Generic node server.js — bring your own command.', 'framework' => 'node'],
    ];

    /** Optional site context (sites.queue-workers route binds the Site). null = server-wide view. */
    public ?string $context_site_id = null;

    /** workers | add */
    public string $queue_workspace_tab = 'workers';

    public function setQueueWorkspaceTab(string $tab): void
    {
        $this->queue_workspace_tab = in_array($tab, ['workers', 'add'], true) ? $tab : 'workers';
    }

    public function mount(Server $server, ?Site $site = null): void
    {
        if ($site !== null) {
            abort_unless($site->server_id === $server->id, 404);
            abort_unless($server->organization_id === auth()->user()?->currentOrganization()?->id, 404);
            Gate::authorize('view', $site);
            $this->context_site_id = $site->id;
        }

        $this->bootWorkspace($server);
        $this->initSupervisorInstallStatus($server);
    }

    public function restartWorker(SupervisorProvisioner $provisioner, string $programId): void
    {
        $this->workerAction($provisioner, $programId, 'restart');
    }

    public function stopWorker(SupervisorProvisioner $provisioner, string $programId): void
    {
        $this->workerAction($provisioner, $programId, 'stop');
    }

    public function startWorker(SupervisorProvisioner $provisioner, string $programId): void
    {
        $this->workerAction($provisioner, $programId, 'start');
    }

    /**
     * Shared verb dispatcher — keeps the three public actions thin and ensures
     * every supervisor lifecycle change goes through the same auth + audit path.
     * When in site context, restrict lookups + audit payload to that site so
     * the action can't cross site boundaries via crafted IDs.
     */
    private function workerAction(SupervisorProvisioner $provisioner, string $programId, string $verb): void
    {
        $this->authorize('update', $this->server);

        $query = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereIn('program_type', self::QUEUE_TYPES)
            ->whereKey($programId);
        if ($this->context_site_id !== null) {
            $query->where('site_id', $this->context_site_id);
        }
        $program = $query->firstOrFail();

        try {
            match ($verb) {
                'restart' => $provisioner->restartProgramGroup($this->server->fresh(), $program->id),
                'stop' => $provisioner->stopProgramGroup($this->server->fresh(), $program->id),
                'start' => $provisioner->startProgramGroup($this->server->fresh(), $program->id),
            };
            if ($org = $this->server->organization) {
                audit_log($org, auth()->user(), 'queue_worker.'.$verb, $program, null, array_filter([
                    'slug' => $program->slug,
                    'program_type' => $program->program_type,
                    'site_id' => $this->context_site_id,
                ], static fn ($v) => $v !== null));
            }
            $this->toastSuccess(__(':verb sent to :slug.', ['verb' => ucfirst($verb), 'slug' => $program->slug]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function render(): View
    {
        $this->server->refresh();

        $contextSiteModel = $this->context_site_id !== null
            ? Site::query()->where('server_id', $this->server->id)->whereKey($this->context_site_id)->first()
            : null;

        $programsQuery = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereIn('program_type', self::QUEUE_TYPES);
        if ($contextSiteModel !== null) {
            $programsQuery->where('site_id', $contextSiteModel->id);
        }
        $programs = $programsQuery->orderBy('slug')->get();

        $stats = [
            'active' => $programs->where('is_active', true)->count(),
            'inactive' => $programs->where('is_active', false)->count(),
            'total_processes' => (int) $programs->where('is_active', true)->sum('numprocs'),
        ];

        return view('livewire.servers.workspace-queue-workers', [
            'opsReady' => $this->serverOpsReady(),
            'programs' => $programs,
            'presets' => self::PRESETS,
            'stats' => $stats,
            'contextSiteModel' => $contextSiteModel,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
