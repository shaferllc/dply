<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerCertificateInventory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceCertInventory extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.cert_inventory';

    public bool $showRenewModal = false;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        abort_unless($server->isVmHost(), 404);
    }

    public function openRenewModal(): void
    {
        $this->authorize('update', $this->server);
        $this->showRenewModal = true;
        $this->dispatch('open-modal', 'cert-inventory-renew');
    }

    public function closeRenewModal(): void
    {
        $this->showRenewModal = false;
        $this->dispatch('close-modal', 'cert-inventory-renew');
    }

    public function queueBulkRenew(ServerCertificateInventory $inventory): void
    {
        $this->authorize('update', $this->server);

        $result = $inventory->queueRenewals($this->server);
        $this->closeRenewModal();

        if ($result['queued'] === 0) {
            $this->toastError(__('No renewable certificates matched the expiring/failed criteria.'));

            return;
        }

        $this->toastSuccess(trans_choice(
            'Queued :count certificate renewal|Queued :count certificate renewals',
            $result['queued'],
            ['count' => $result['queued']],
        ));
    }

    public function render(ServerCertificateInventory $inventory): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-cert-inventory', [
            'report' => $inventory->forServer($this->server),
        ]);
    }
}
