<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\SiteDeployment;

/**
 * Shared deployment payload shaping for the deploy MCP tools, mirroring the
 * REST API's SiteController::deploymentPayload so MCP and HTTP agree on shape.
 */
trait SerializesDeployments
{
    /**
     * Compact row for list views — omits the (potentially large) log output.
     *
     * @return array<string, mixed>
     */
    protected function deploymentSummary(SiteDeployment $d): array
    {
        return [
            'id' => $d->id,
            'site_id' => $d->site_id,
            'trigger' => $d->trigger,
            'status' => $d->status,
            'git_sha' => $d->git_sha,
            'exit_code' => $d->exit_code,
            'started_at' => $d->started_at?->toIso8601String(),
            'finished_at' => $d->finished_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    /**
     * Full detail including the deploy log — for get_deployment.
     *
     * @return array<string, mixed>
     */
    protected function deploymentDetail(SiteDeployment $d): array
    {
        return [
            ...$this->deploymentSummary($d),
            'log_output' => $d->log_output,
        ];
    }
}
