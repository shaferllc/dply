<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use Livewire\Attributes\Locked;

/**
 * @property Site $site
 */
trait MountsEdgeWorkspaceSection
{
    #[Locked]
    public Server $server;

    #[Locked]
    public Site $site;

    public function mountEdgeWorkspaceSection(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        if (! $site->usesEdgeRuntime()) {
            abort(404);
        }

        $currentOrganization = request()->user()?->currentOrganization();
        if ($server->organization_id !== $currentOrganization?->id) {
            abort(404);
        }

        $this->authorize('view', $site);

        $site->setRelation('server', $server);
        if ($currentOrganization !== null && $site->organization_id === $currentOrganization->id) {
            $server->setRelation('organization', $currentOrganization);
            $site->setRelation('organization', $currentOrganization);
        }

        $this->server = $server;
        $this->site = $site;
    }

    /**
     * Latest live (or most recent) deploy's repo_config section for Advanced UI.
     *
     * @return array{source_path: string, section: array<string, mixed>}
     */
    protected function edgeRepoConfigSection(string $key): array
    {
        $latest = EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();

        $repo = is_array($latest?->repo_config) ? $latest->repo_config : [];
        $section = is_array($repo[$key] ?? null) ? $repo[$key] : [];

        return [
            'source_path' => is_string($repo['source_path'] ?? null) ? (string) $repo['source_path'] : 'dply.yaml',
            'section' => $section,
        ];
    }
}
