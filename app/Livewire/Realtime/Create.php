<?php

declare(strict_types=1);

namespace App\Livewire\Realtime;

use App\Actions\Realtime\CreateRealtimeApp;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Forms\RealtimeCreateForm;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    use DispatchesToastNotifications;

    public RealtimeCreateForm $form;

    public function mount(): void
    {
        abort_if(auth()->user()?->currentOrganization() === null, 403);
    }

    public function create(CreateRealtimeApp $action): void
    {
        $this->validate();

        $org = auth()->user()?->currentOrganization();
        if (! $org instanceof Organization) {
            $this->toastError(__('No active organization.'));

            return;
        }

        $app = $action->handle(auth()->user(), $org, $this->form->toArray());

        $this->toastSuccess(__('Realtime app “:name” is provisioning.', ['name' => $app->name]));
        $this->redirect(route('realtime.show', $app), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.realtime.create', [
            'priceCents' => (int) config('realtime.plan.price_cents'),
            'maxConnections' => (int) config('realtime.plan.max_connections'),
        ]);
    }
}
