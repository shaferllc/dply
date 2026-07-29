<?php

declare(strict_types=1);

namespace App\Livewire\Live\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Services\ProductionData\ProductionApiException;
use App\Services\ProductionData\ProductionSiteMaterializer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Production Manage entry: materialize the remote site into a local Site/Server
 * stub (when needed), then open the real site workspace — never the thin API UI.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use DispatchesToastNotifications;
    use InteractsWithProductionData;

    public string $remoteSiteId = '';

    public ?string $error = null;

    public function mount(string $remoteSite, ProductionSiteMaterializer $materializer): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }

        $this->remoteSiteId = $remoteSite;
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        try {
            $site = $materializer->open($connection, $remoteSite, $user);
            if ($site->server === null) {
                throw new RuntimeException('Materialized site is missing its server.');
            }

            $this->redirect(route('sites.show', [$site->server, $site]), navigate: true);
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

        return view('livewire.live.sites.show', [
            'connection' => $connection,
            'error' => $this->error,
            'writesUnlocked' => $this->productionMirror()->writesUnlocked(),
        ]);
    }
}
