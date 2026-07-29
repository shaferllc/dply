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

/**
 * Third-party tag / script manager (customer-facing name; Zaraz-class capability).
 */
class Tags extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public bool $consent_required = false;

    /** @var list<array{name: string, src: string, async: bool}> */
    public array $tools = [];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['tags'] ?? null) ? $site->edgeMeta()['tags'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->consent_required = (bool) ($cfg['consent_required'] ?? false);
        $tools = is_array($cfg['tools'] ?? null) ? $cfg['tools'] : [];
        $this->tools = $tools !== [] ? array_values(array_map(fn ($t) => [
            'name' => (string) ($t['name'] ?? 'tag'),
            'src' => (string) ($t['src'] ?? ''),
            'async' => (bool) ($t['async'] ?? true),
        ], $tools)) : [[
            'name' => 'analytics',
            'src' => '',
            'async' => true,
        ]];
    }

    public function addTool(): void
    {
        $this->tools[] = ['name' => 'tag', 'src' => '', 'async' => true];
    }

    public function removeTool(int $index): void
    {
        unset($this->tools[$index]);
        $this->tools = array_values($this->tools);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Tags require Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'tools.*.name' => ['required', 'string', 'max:64'],
            'tools.*.src' => ['nullable', 'url', 'starts_with:https://', 'max:500'],
        ]);

        $this->site->mergeEdgeMeta([
            'tags' => [
                'enabled' => $this->enabled,
                'consent_required' => $this->consent_required,
                'tools' => array_values(array_filter(
                    $this->tools,
                    static fn (array $t): bool => trim((string) ($t['src'] ?? '')) !== '',
                )),
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Tags saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.tags', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-tags'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
            ],
        ));
    }
}
