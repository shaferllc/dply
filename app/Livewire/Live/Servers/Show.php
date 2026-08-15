<?php

declare(strict_types=1);

namespace App\Livewire\Live\Servers;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Services\ProductionData\ProductionApiException;
use App\Services\ProductionData\ProductionServerMaterializer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Production Manage entry: materialize the remote server into a local Server
 * stub (when needed), then open the real server workspace — the server-side
 * twin of Live\Sites\Show, so Manage stays inside this app instead of
 * bouncing out to the remote control plane.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use DispatchesToastNotifications;
    use InteractsWithProductionData;

    public string $remoteServerId = '';

    public ?string $error = null;

    public function mount(string $remoteServer, ProductionServerMaterializer $materializer): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }

        $this->remoteServerId = $remoteServer;
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        try {
            $server = $materializer->open($connection, $remoteServer, $user);

            $this->redirect(route('servers.show', $server), navigate: true);
        } catch (ProductionApiException $e) {
            if ($e->isUnauthorized()) {
                $this->handleProductionApiError($e);

                return;
            }
            $this->error = $e->getMessage();
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $connection = $this->productionConnection
            ?? new \App\Models\ProductionDataConnection(['base_url' => '']);

        return view('livewire.live.servers.show', [
            'connection' => $connection,
            'error' => $this->error,
            'writesUnlocked' => $this->productionMirror()->writesUnlocked(),
        ]);
    }
}
