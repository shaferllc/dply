<?php

declare(strict_types=1);

namespace App\Http\Resources\Edge;

use App\Models\EdgeDeployment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-API representation of an EdgeDeployment row.
 *
 * @property EdgeDeployment $resource
 */
final class EdgeDeploymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deployment = $this->resource;
        $repoConfig = $deployment->repo_config;

        return [
            'id' => (string) $deployment->id,
            'site_id' => (string) $deployment->site_id,
            'status' => $deployment->status,
            'git_commit' => $deployment->git_commit,
            'git_branch' => $deployment->git_branch,
            'storage_prefix' => $deployment->storage_prefix,
            'pruned' => $deployment->pruned_at !== null,
            'cf_kv_version' => $deployment->cf_kv_version,
            'aliases' => $deployment->aliasHostnames(),
            'repo_config' => [
                'source_path' => $repoConfig['source_path'] ?? null,
                'build_overrides' => $repoConfig['build'] ?? [],
                'redirect_count' => count((array) ($repoConfig['redirects'] ?? [])),
                'rewrite_count' => count((array) ($repoConfig['rewrites'] ?? [])),
                'header_rule_count' => count((array) ($repoConfig['headers'] ?? [])),
                'warning_count' => count((array) ($repoConfig['warnings'] ?? [])),
            ],
            'failure_reason' => $deployment->failure_reason,
            'published_at' => $deployment->published_at?->toIso8601String(),
            'failed_at' => $deployment->failed_at?->toIso8601String(),
            'created_at' => $deployment->created_at->toIso8601String(),
        ];
    }
}
