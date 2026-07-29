<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Models\EdgeDeployment;
use App\Modules\Edge\Services\EdgeHostMapPublisher;

trait PublishesEdgeHostMap
{
    protected function republishEdgeHostMap(): void
    {
        try {
            $live = EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->latest('id')
                ->first();
            if ($live !== null) {
                app(EdgeHostMapPublisher::class)->publish($this->site->fresh(), $live);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function isManagedEdgeDelivery(): bool
    {
        return ($this->site->edge_backend ?? '') === 'dply_edge';
    }
}
