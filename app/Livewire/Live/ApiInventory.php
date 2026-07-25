<?php

declare(strict_types=1);

namespace App\Livewire\Live;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Models\ProductionDataConnection;
use App\Services\ProductionData\ProductionApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Generic Production inventory page backed only by the remote control-plane API.
 */
#[Layout('layouts.app')]
class ApiInventory extends Component
{
    use DispatchesToastNotifications;
    use InteractsWithProductionData;

    public string $resource = 'projects';

    public function mount(): void
    {
        if ($this->requireProductionConnection() === null) {
            return;
        }

        $this->resource = match (true) {
            request()->routeIs('live.projects.*') => 'projects',
            request()->routeIs('live.edge.*') => 'edge',
            request()->routeIs('live.cloud.*') => 'cloud',
            request()->routeIs('live.serverless.*') => 'serverless',
            default => abort(404),
        };
    }

    public function refresh(): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }
        $this->productionMirror()->forget($connection, 'inventory:'.$this->resource);
        $this->toastSuccess(__('Refreshed from production.'));
    }

    public function disconnect(): void
    {
        $connection = $this->productionConnection;
        if ($connection === null) {
            return;
        }

        $this->productionMirror()->disconnect($connection);
        $this->toastWarning(__('Disconnected from production.'));
        $this->redirect(route('live.connect'), navigate: true);
    }

    public function render(): View
    {
        $connection = $this->requireProductionConnection()
            ?? new ProductionDataConnection(['base_url' => '']);

        $apiReady = in_array($this->resource, ['projects', 'edge'], true);
        $rows = [];
        $error = null;

        if ($apiReady && $connection->exists) {
            try {
                $rows = $this->productionMirror()->remember(
                    $connection,
                    'inventory:'.$this->resource,
                    function ($client) {
                        return match ($this->resource) {
                            'projects' => $client->projects(),
                            'edge' => $client->edgeSites(),
                            default => [],
                        };
                    },
                );
                $rows = array_map(function (array $row): array {
                    return [
                        'name' => $row['name'] ?? $row['slug'] ?? '—',
                        'id' => $row['id'] ?? '—',
                        'detail' => $row['status']
                            ?? $row['hostname']
                            ?? (isset($row['servers_count']) ? ((int) $row['servers_count']).' '.__('servers') : null)
                            ?? $row['role']
                            ?? '—',
                        'status' => $row['status'] ?? null,
                        'hostname' => $row['hostname'] ?? null,
                        'servers_count' => $row['servers_count'] ?? null,
                        'role' => $row['role'] ?? null,
                        'slug' => $row['slug'] ?? null,
                    ];
                }, $rows);
            } catch (ProductionApiException $e) {
                if ($e->isUnauthorized()) {
                    $this->handleProductionApiError($e);
                } else {
                    $error = $e->getMessage();
                }
            }
        }

        return view('livewire.live.api-inventory', [
            'connection' => $connection,
            'rows' => $rows,
            'error' => $error,
            'title' => $this->title(),
            'apiReady' => $apiReady,
            'writesUnlocked' => $this->productionMirror()->writesUnlocked(),
        ]);
    }

    protected function title(): string
    {
        return match ($this->resource) {
            'projects' => __('Projects'),
            'edge' => __('Edge'),
            'cloud' => __('Cloud apps'),
            'serverless' => __('Serverless'),
            default => __('Production'),
        };
    }
}
