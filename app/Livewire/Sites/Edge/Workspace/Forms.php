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

class Forms extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    /** @var list<array{path: string, to_email: string, honeypot: string, require_turnstile: bool}> */
    public array $endpoints = [];

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['forms'] ?? null) ? $site->edgeMeta()['forms'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $endpoints = is_array($cfg['endpoints'] ?? null) ? $cfg['endpoints'] : [];
        $this->endpoints = $endpoints !== [] ? array_values(array_map(fn ($e) => [
            'path' => (string) ($e['path'] ?? '/contact'),
            'to_email' => (string) ($e['to_email'] ?? ''),
            'honeypot' => (string) ($e['honeypot'] ?? 'company'),
            'require_turnstile' => (bool) ($e['require_turnstile'] ?? true),
        ], $endpoints)) : [[
            'path' => '/contact',
            'to_email' => (string) (auth()->user()?->email ?? ''),
            'honeypot' => 'company',
            'require_turnstile' => true,
        ]];
    }

    public function addEndpoint(): void
    {
        $this->endpoints[] = [
            'path' => '/contact',
            'to_email' => '',
            'honeypot' => 'company',
            'require_turnstile' => true,
        ];
    }

    public function removeEndpoint(int $index): void
    {
        unset($this->endpoints[$index]);
        $this->endpoints = array_values($this->endpoints);
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Forms require Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'endpoints.*.path' => ['required', 'string', 'max:255'],
            'endpoints.*.to_email' => ['required', 'email'],
            'endpoints.*.honeypot' => ['nullable', 'string', 'max:64'],
        ]);

        $this->site->mergeEdgeMeta([
            'forms' => [
                'enabled' => $this->enabled,
                'endpoints' => array_values($this->endpoints),
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Forms saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.forms', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-forms'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
            ],
        ));
    }
}
