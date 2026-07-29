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

class BotProtection extends Component
{
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;
    use PublishesEdgeHostMap;

    public bool $enabled = false;

    public string $site_key = '';

    public string $secret_key = '';

    public string $mode = 'forms';

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $cfg = is_array($site->edgeMeta()['turnstile'] ?? null) ? $site->edgeMeta()['turnstile'] : [];
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->site_key = (string) ($cfg['site_key'] ?? '');
        $this->secret_key = (string) ($cfg['secret_key'] ?? '');
        $mode = (string) ($cfg['mode'] ?? 'forms');
        $this->mode = in_array($mode, ['forms', 'all'], true) ? $mode : 'forms';
    }

    public function save(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->isManagedEdgeDelivery()) {
            $this->toastError(__('Bot protection requires Dply-hosted Edge delivery.'));

            return;
        }

        $this->validate([
            'site_key' => ['required_if:enabled,true', 'string', 'max:200'],
            'secret_key' => ['required_if:enabled,true', 'string', 'max:200'],
            'mode' => ['required', 'in:forms,all'],
        ]);

        $this->site->mergeEdgeMeta([
            'turnstile' => [
                'enabled' => $this->enabled,
                'site_key' => trim($this->site_key),
                'secret_key' => trim($this->secret_key),
                'mode' => $this->mode,
            ],
        ]);
        $this->site->save();
        $this->republishEdgeHostMap();
        $this->toastSuccess(__('Bot protection saved.'));
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.bot-protection', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-bot-protection'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'managedDelivery' => $this->isManagedEdgeDelivery(),
            ],
        ));
    }
}
