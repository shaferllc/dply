<?php

declare(strict_types=1);

namespace App\Livewire\Realtime;

use App\Actions\Realtime\DeleteRealtimeApp;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\RealtimeApp;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use DispatchesToastNotifications;

    public RealtimeApp $app;

    public function mount(RealtimeApp $realtimeApp): void
    {
        $this->authorizeOwnership($realtimeApp);
        $this->app = $realtimeApp;
    }

    /** Polled by the view while the app is still provisioning. */
    public function refreshStatus(): void
    {
        $this->app->refresh();
    }

    public function delete(DeleteRealtimeApp $action): void
    {
        $app = $this->app;
        $this->authorizeOwnership($app);

        $action->handle($app);

        $this->toastSuccess(__('Realtime app deleted.'));
        $this->redirect(route('realtime.index'), navigate: true);
    }

    private function authorizeOwnership(RealtimeApp $app): void
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null || $app->organization_id !== $org->id, 403);
    }

    public function render(): View
    {
        return view('livewire.realtime.show', [
            'priceCents' => (int) config('realtime.plan.price_cents'),
        ]);
    }
}
