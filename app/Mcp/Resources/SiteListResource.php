<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Concerns\ResolvesDplyContext;
use App\Models\Site;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/**
 * Snapshot of the organization's sites so an AI client can ground itself without
 * a tool call. Mirrors the list_sites tool output.
 */
#[Uri('dply://sites')]
#[MimeType('application/json')]
#[Description('The list of sites in the authenticated dply organization (id, name, server, runtime, status).')]
class SiteListResource extends Resource
{
    use ResolvesDplyContext;

    protected string $name = 'sites';

    public function handle(Request $request): Response
    {
        $organization = $this->organization();

        $sites = Site::query()
            ->whereHas('server', fn ($q) => $q->where('organization_id', $organization->id))
            ->with(['server:id,name'])
            ->orderBy('name')
            ->get(['id', 'server_id', 'name', 'slug', 'type', 'runtime', 'status', 'last_deploy_at']);

        return Response::json([
            'data' => $sites->map(fn (Site $s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => $s->name,
                'server_id' => $s->server_id,
                'server_name' => $s->server?->name,
                'type' => $s->type,
                'runtime' => $s->runtime,
                'status' => $s->status,
                'last_deploy_at' => $s->last_deploy_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
