<?php

namespace App\Livewire\Sites;

use App\Livewire\Servers\WorkspaceQueueWorkers;
use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Services\Servers\SupervisorProvisioner;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-site lens on queue / background workers — same model as
 * {@see WorkspaceQueueWorkers} but filtered to programs that belong to this site.
 *
 * Renders inside the site workspace shell (sidebar + breadcrumbs scoped to the
 * site), and routes "Add worker" CTAs to the site's daemons page so the wizard
 * pre-fills directory and system user from the site context.
 */
#[Layout('layouts.app')]
class SiteQueueWorkers extends Component
{
    public Server $server;

    public Site $site;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
    }

    public function restartWorker(SupervisorProvisioner $provisioner, string $programId): void
    {
        Gate::authorize('update', $this->server);

        $program = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $this->site->id)
            ->whereIn('program_type', WorkspaceQueueWorkers::QUEUE_TYPES)
            ->whereKey($programId)
            ->firstOrFail();

        try {
            $provisioner->restartProgramGroup($this->server->fresh(), $program->id);
            session()->flash('toast.success', __('Restart sent to :slug.', ['slug' => $program->slug]));
        } catch (\Throwable $e) {
            session()->flash('toast.error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $programs = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $this->site->id)
            ->whereIn('program_type', WorkspaceQueueWorkers::QUEUE_TYPES)
            ->orderBy('slug')
            ->get();

        // Same preset deck as the server-level page; the route changes per click.
        $presets = WorkspaceQueueWorkers::PRESETS;

        // Section IDs the site sidebar uses for active highlighting + tab state preservation.
        return view('livewire.sites.site-queue-workers', [
            'site' => $this->site,
            'server' => $this->server,
            'programs' => $programs,
            'presets' => $presets,
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'section' => 'queue-workers',
            'resourceNoun' => __('Site'),
            'resourcePlural' => __('Sites'),
            'routingTab' => 'domains',
        ]);
    }
}
