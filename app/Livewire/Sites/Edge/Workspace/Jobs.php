<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Jobs extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public string $default_queue = 'JOBS';

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['jobs'] ?? null) ? $site->edgeMeta()['jobs'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->default_queue = trim((string) ($cfg['default_queue'] ?? 'JOBS')) ?: 'JOBS';
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Edge jobs require Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'default_queue' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
        ]);

        $this->site->mergeEdgeMeta([
            'jobs' => [
                'enabled' => $this->enabled,
                'default_queue' => $this->default_queue,
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Edge jobs settings saved.'));
    }

    public function render(): View
    {
        $live = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first();
        $bindings = EdgeEffectiveBindings::for($this->site, $live);
        $queues = [];
        foreach ($bindings as $binding) {
            if (($binding['kind'] ?? '') === 'queue') {
                $queues[] = $binding;
            }
        }

        return view('livewire.sites.edge.workspace.jobs', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-jobs'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
                'queueBindings' => $queues,
                'bindingsUrl' => route('sites.show', [
                    'server' => $this->server,
                    'site' => $this->site,
                    'section' => 'edge-bindings',
                ]),
            ],
        ));
    }
}
