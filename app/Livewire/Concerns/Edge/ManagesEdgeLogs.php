<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesEdgeLogs
{
    /** @var array<string, bool> */
    public array $edgeDeploymentBuildLogsLoaded = [];

    public function loadEdgeDeploymentBuildLog(string $deploymentId): void
    {
        if (isset($this->edgeDeploymentBuildLogsLoaded[$deploymentId])) {
            return;
        }

        $deployment = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->find($deploymentId);

        if ($deployment === null) {
            $this->edgeDeploymentBuildLogsLoaded[$deploymentId] = true;

            return;
        }

        $log = $deployment->readBuildLog($this->site);
        Cache::put(
            $this->edgeDeploymentBuildLogCacheKey($deploymentId),
            $log ?? '',
            now()->addHour(),
        );
        $this->edgeDeploymentBuildLogsLoaded[$deploymentId] = true;
    }

    public function edgeDeploymentBuildLog(string $deploymentId): ?string
    {
        if (! isset($this->edgeDeploymentBuildLogsLoaded[$deploymentId])) {
            return null;
        }

        if (! Cache::has($this->edgeDeploymentBuildLogCacheKey($deploymentId))) {
            return null;
        }

        $log = Cache::get($this->edgeDeploymentBuildLogCacheKey($deploymentId));

        return is_string($log) && $log !== '' ? $log : null;
    }

    public function refreshEdgeLogDeployments(): void
    {
        $this->site->load([
            'edgeDeployments' => fn ($query) => $query->limit(10),
        ]);
    }

    private function edgeDeploymentBuildLogCacheKey(string $deploymentId): string
    {
        return 'edge.build_log.'.$this->site->id.'.'.$deploymentId;
    }

    /**
     * @return Collection<int, EdgeDeployment>
     */
    public function edgeDeploymentHistory(int $limit = 10): Collection
    {
        return $this->site->edgeDeployments()->limit($limit)->get();
    }
}
