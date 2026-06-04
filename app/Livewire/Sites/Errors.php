<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\SurfacesErrorStream;
use App\Models\ErrorEvent;
use App\Models\Server;
use App\Models\Site;
use App\Support\SiteSettingsSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The site's "Errors" view — a chronological stream of this site's failures
 * (deploys, SSL, bindings, env, …). Stream behaviour lives in
 * {@see SurfacesErrorStream}; this is the per-site scope + the settings shell.
 */
#[Layout('layouts.app')]
class Errors extends Component
{
    use DispatchesToastNotifications;
    use SurfacesErrorStream;
    use WithPagination;

    public Server $server;

    public Site $site;

    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);
        Gate::authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
    }

    protected function scopedErrors(): Builder
    {
        return ErrorEvent::query()->forSite((string) $this->site->id);
    }

    protected function authorizeErrorAccess(): void
    {
        Gate::authorize('update', $this->site);
    }

    public function render(): View
    {
        $runtimeMode = $this->site->runtimeTargetMode();

        return view('livewire.sites.errors', [
            'settingsSidebarItems' => SiteSettingsSidebar::items($this->site, $this->server),
            'resourceNoun' => $runtimeMode === 'vm' ? __('Site') : __('App'),
            'resourcePlural' => $runtimeMode === 'vm' ? __('sites') : __('apps'),
            'routingTab' => 'domains',
            'laravel_tab' => 'commands',
            'section' => 'errors',
            'runtimeMode' => $runtimeMode,
        ]);
    }
}
