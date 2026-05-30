<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Actions\Edge\CreateEdgePreviewSite;
use App\Models\Organization;
use App\Models\Site;
use App\Services\DeployContract\DeployContractState;
use Laravel\Pennant\Feature;

/**
 * Org-wide Edge preview deploy contract status for Fleet dashboard.
 */
final class DeployContractFleetCatalog
{
    public function __construct(
        private readonly DeployContractState $contractState,
    ) {}

    /**
     * @return list<array{
     *   preview_id: string,
     *   preview_name: string,
     *   parent_id: string,
     *   parent_name: string,
     *   branch: ?string,
     *   status: ?string,
     *   ready: bool,
     *   failed_count: int,
     *   href: string,
     *   run_at: ?string,
     * }>
     */
    public function forOrganization(Organization $organization): array
    {
        if (! Feature::active('global.deploy_contract')) {
            return [];
        }

        $parents = Site::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $s) => $s->usesEdgeRuntime() && ! $s->isEdgePreview());

        $rows = [];

        foreach ($parents as $parent) {
            foreach (CreateEdgePreviewSite::listForParent($parent) as $preview) {
                $contract = $this->contractState->forPreview($parent, $preview);
                $edge = $preview->edgeMeta();

                $rows[] = [
                    'preview_id' => (string) $preview->id,
                    'preview_name' => $preview->name,
                    'parent_id' => (string) $parent->id,
                    'parent_name' => $parent->name,
                    'branch' => isset($edge['preview_branch']) ? (string) $edge['preview_branch'] : null,
                    'status' => is_string($contract['status'] ?? null) ? $contract['status'] : null,
                    'ready' => ! empty($contract['ready_to_promote']),
                    'failed_count' => (int) ($contract['failed_count'] ?? 0),
                    'href' => route('sites.preview-comments', [
                        'server' => $preview->server_id,
                        'site' => $preview,
                    ]),
                    'run_at' => $contract['finished_at'] ?? null,
                ];
            }
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['ready'] !== $b['ready']) {
                return $a['ready'] ? 1 : -1;
            }

            return strcmp($a['parent_name'], $b['parent_name']);
        });

        return $rows;
    }

    /**
     * @return array{total: int, ready: int, blocked: int, not_run: int}
     */
    public function counts(Organization $organization): array
    {
        $rows = $this->forOrganization($organization);

        $ready = 0;
        $blocked = 0;
        $notRun = 0;

        foreach ($rows as $row) {
            if ($row['ready']) {
                $ready++;
            } elseif ($row['status'] === null) {
                $notRun++;
            } else {
                $blocked++;
            }
        }

        return [
            'total' => count($rows),
            'ready' => $ready,
            'blocked' => $blocked,
            'not_run' => $notRun,
        ];
    }
}
