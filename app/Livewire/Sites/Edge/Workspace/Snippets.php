<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Livewire\Concerns\Edge\PublishesEdgeHostMap;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Snippets extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    /** @var list<array{name: string, phase: string, path: string, html: string}> */
    public array $items = [];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['snippets'] ?? null) ? $site->edgeMeta()['snippets'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $items = is_array($cfg['items'] ?? null) ? $cfg['items'] : [];
        $this->items = $items !== [] ? array_values(array_map(fn ($i) => [
            'name' => (string) ($i['name'] ?? 'snippet'),
            'phase' => in_array(($i['phase'] ?? 'head'), ['head', 'body'], true) ? (string) $i['phase'] : 'head',
            'path' => (string) ($i['path'] ?? '/*'),
            'html' => (string) ($i['html'] ?? ''),
        ], $items)) : [[
            'name' => 'custom',
            'phase' => 'head',
            'path' => '/*',
            'html' => '',
        ]];
    }

    public function addItem(): void
    {
        $this->items[] = ['name' => 'snippet', 'phase' => 'head', 'path' => '/*', 'html' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Snippets require Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'items.*.name' => ['required', 'string', 'max:64'],
            'items.*.phase' => ['required', 'in:head,body'],
            'items.*.path' => ['required', 'string', 'max:255'],
            'items.*.html' => ['nullable', 'string', 'max:8000'],
        ]);

        $this->site->mergeEdgeMeta([
            'snippets' => [
                'enabled' => $this->enabled,
                'items' => array_values($this->items),
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Snippets saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.snippets', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-snippets'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
            ],
        ));
    }
}
