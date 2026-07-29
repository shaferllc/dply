<?php

declare(strict_types=1);

namespace App\Livewire\Live\Concerns;

use App\Models\ProductionDataConnection;
use App\Services\ProductionData\ProductionApiException;
use App\Services\ProductionData\ProductionDataMirror;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property-read ?ProductionDataConnection $productionConnection
 */
trait InteractsWithProductionData
{
    public function getProductionConnectionProperty(): ?ProductionDataConnection
    {
        return app(ProductionDataMirror::class)->connectionFor(Auth::user());
    }

    protected function productionMirror(): ProductionDataMirror
    {
        return app(ProductionDataMirror::class);
    }

    protected function requireProductionConnection(): ?ProductionDataConnection
    {
        $connection = $this->productionConnection;
        if ($connection instanceof ProductionDataConnection) {
            return $connection;
        }

        $this->redirect(route('live.connect'), navigate: true);

        return null;
    }

    protected function handleProductionApiError(ProductionApiException $e): void
    {
        if ($e->isUnauthorized()) {
            $this->toastError(__('Production connection expired. Connect again.'));
            $this->redirect(route('live.connect'), navigate: true);

            return;
        }

        $this->toastError($e->getMessage());
    }
}
