<?php

declare(strict_types=1);

namespace App\Livewire\Live;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Models\ProductionDataConnection;
use App\Models\Site;
use App\Services\ProductionData\ProductionApiException;
use App\Support\Cloud\CloudIndexRow;
use App\Support\Edge\EdgeIndexRow;
use App\Support\Projects\ProjectIndexRow;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

        $apiReady = in_array($this->resource, ['projects', 'edge', 'cloud'], true);
        $rows = [];
        $error = null;
        /** @var Collection<int, ProjectIndexRow> $projectRows */
        $projectRows = collect();
        /** @var Collection<int, EdgeIndexRow> $edgeRows */
        $edgeRows = collect();
        /** @var Collection<int, CloudIndexRow> $cloudRows */
        $cloudRows = collect();
        $summary = [
            'projects' => 0,
            'servers' => 0,
            'sites' => 0,
            'members' => 0,
        ];
        $edgeTotals = [
            'all' => 0,
            'active' => 0,
            'provisioning' => 0,
            'previews' => 0,
            'failed' => 0,
        ];
        $cloudTotals = [
            'all' => 0,
            'active' => 0,
            'provisioning' => 0,
            'source' => 0,
            'image' => 0,
            'previews' => 0,
            'failed' => 0,
        ];
        /** @var Collection<int, \App\Support\Serverless\ServerlessIndexRow> $serverlessRows */
        $serverlessRows = collect();
        $serverlessTotals = [
            'all' => 0,
            'live' => 0,
            'deploying' => 0,
        ];

        if ($apiReady && $connection->exists) {
            try {
                $rows = $this->productionMirror()->remember(
                    $connection,
                    'inventory:'.$this->resource,
                    function ($client) {
                        return match ($this->resource) {
                            'projects' => $client->projects(),
                            'edge' => $client->edgeSites(),
                            'cloud' => $client->cloudSites(),
                            default => [],
                        };
                    },
                );

                if ($this->resource === 'projects') {
                    $projectRows = collect($rows)
                        ->map(fn (array $row): ProjectIndexRow => ProjectIndexRow::fromProductionApi($row))
                        ->values();
                    $summary = [
                        'projects' => $projectRows->count(),
                        'servers' => (int) $projectRows->sum(fn (ProjectIndexRow $row): int => $row->serversCount),
                        'sites' => (int) $projectRows->sum(fn (ProjectIndexRow $row): int => $row->sitesCount),
                        'members' => 0,
                    ];
                } elseif ($this->resource === 'edge') {
                    [$edgeRows, $edgeTotals] = $this->edgeInventoryFromApiRows($rows);
                } elseif ($this->resource === 'cloud') {
                    [$cloudRows, $cloudTotals] = $this->cloudInventoryFromApiRows($rows);
                } else {
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
                }
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
            'resource' => $this->resource,
            'rows' => $rows,
            'projectRows' => $projectRows,
            'edgeRows' => $edgeRows,
            'cloudRows' => $cloudRows,
            'serverlessRows' => $serverlessRows,
            'summary' => $summary,
            'edgeTotals' => $edgeTotals,
            'cloudTotals' => $cloudTotals,
            'serverlessTotals' => $serverlessTotals,
            'hasProjectsInScope' => $projectRows->isNotEmpty(),
            'hasEdgeSitesInScope' => $edgeTotals['all'] > 0,
            'hasCloudAppsInScope' => $cloudTotals['all'] > 0,
            'hasServerlessInScope' => $serverlessTotals['all'] > 0,
            'error' => $error,
            'title' => $this->title(),
            'apiReady' => $apiReady,
            'writesUnlocked' => $this->productionMirror()->writesUnlocked(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: Collection<int, EdgeIndexRow>, 1: array{all: int, active: int, provisioning: int, previews: int, failed: int}}
     */
    private function edgeInventoryFromApiRows(array $rows): array
    {
        $raw = collect($rows)->values();
        $isPreview = fn (array $row): bool => (bool) ($row['is_preview'] ?? false)
            || filled($row['parent_site_id'] ?? null);
        $parentOf = fn (array $row): ?string => isset($row['parent_site_id']) && is_string($row['parent_site_id']) && $row['parent_site_id'] !== ''
            ? $row['parent_site_id']
            : null;

        $totals = [
            'all' => $raw->count(),
            'active' => $raw->where('status', Site::STATUS_EDGE_ACTIVE)->count(),
            'provisioning' => $raw->where('status', Site::STATUS_EDGE_PROVISIONING)->count(),
            'previews' => $raw->filter($isPreview)->count(),
            'failed' => $raw->where('status', Site::STATUS_EDGE_FAILED)->count(),
        ];

        $previewsByParent = $raw->filter($isPreview)->groupBy(fn (array $row): string => (string) ($parentOf($row) ?? ''));
        $assignedChildIds = [];
        $ordered = collect();

        foreach ($raw as $row) {
            if ($isPreview($row)) {
                continue;
            }
            $ordered->push(['row' => $row, 'preview_child' => false]);
            $children = $previewsByParent->get((string) ($row['id'] ?? ''), collect());
            foreach ($children as $child) {
                $ordered->push(['row' => $child, 'preview_child' => true]);
                $assignedChildIds[(string) ($child['id'] ?? '')] = true;
            }
        }

        foreach ($raw as $row) {
            if (! $isPreview($row) || isset($assignedChildIds[(string) ($row['id'] ?? '')])) {
                continue;
            }
            $ordered->push(['row' => $row, 'preview_child' => true]);
        }

        $edgeRows = $ordered
            ->map(fn (array $item): EdgeIndexRow => EdgeIndexRow::fromProductionApi(
                $item['row'],
                (bool) $item['preview_child'],
            ))
            ->values();

        return [$edgeRows, $totals];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: Collection<int, CloudIndexRow>, 1: array{all: int, active: int, provisioning: int, source: int, image: int, previews: int, failed: int}}
     */
    private function cloudInventoryFromApiRows(array $rows): array
    {
        $raw = collect($rows)->values();
        $isSource = fn (array $row): bool => (bool) ($row['is_source'] ?? false)
            || filled($row['repository'] ?? null)
            || filled($row['git_repo_label'] ?? null)
            || filled($row['git_repository_url'] ?? null);
        $isPreview = fn (array $row): bool => (bool) ($row['is_preview'] ?? false)
            || filled($row['preview_parent_site_id'] ?? null)
            || filled($row['preview_branch'] ?? null);
        $parentOf = fn (array $row): ?string => isset($row['preview_parent_site_id']) && is_string($row['preview_parent_site_id']) && $row['preview_parent_site_id'] !== ''
            ? $row['preview_parent_site_id']
            : null;

        $totals = [
            'all' => $raw->count(),
            'active' => $raw->where('status', Site::STATUS_CONTAINER_ACTIVE)->count(),
            'provisioning' => $raw->where('status', Site::STATUS_CONTAINER_PROVISIONING)->count(),
            'source' => $raw->filter($isSource)->count(),
            'image' => $raw->reject($isSource)->count(),
            'previews' => $raw->filter($isPreview)->count(),
            'failed' => $raw->where('status', Site::STATUS_CONTAINER_FAILED)->count(),
        ];

        $previewsByParent = $raw->filter($isPreview)->groupBy(fn (array $row): string => (string) ($parentOf($row) ?? ''));
        $assignedChildIds = [];
        $ordered = collect();

        foreach ($raw as $row) {
            if ($isPreview($row)) {
                continue;
            }
            $ordered->push(['row' => $row, 'preview_child' => false]);
            $children = $previewsByParent->get((string) ($row['id'] ?? ''), collect());
            foreach ($children as $child) {
                $ordered->push(['row' => $child, 'preview_child' => true]);
                $assignedChildIds[(string) ($child['id'] ?? '')] = true;
            }
        }

        foreach ($raw as $row) {
            if (! $isPreview($row) || isset($assignedChildIds[(string) ($row['id'] ?? '')])) {
                continue;
            }
            $ordered->push(['row' => $row, 'preview_child' => true]);
        }

        $cloudRows = $ordered
            ->map(fn (array $item): CloudIndexRow => CloudIndexRow::fromProductionApi(
                $item['row'],
                (bool) $item['preview_child'],
            ))
            ->values();

        return [$cloudRows, $totals];
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
