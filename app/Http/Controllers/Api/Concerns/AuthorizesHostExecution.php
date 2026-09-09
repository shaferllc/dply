<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Both halves of the check an endpoint needs before it does work on a customer
 * host: the token carries the ability (middleware), the caller's organization
 * owns the resource, AND the caller is authorized for that specific resource.
 *
 * The third was missing across the commands.run surface (ISS-009). Organization
 * membership is not server access: a workspace can hold members who may view a
 * server but not change it, which is exactly what ServerPolicy::update encodes,
 * and an ability check alone lets them install agents and run remediations.
 */
trait AuthorizesHostExecution
{
    /**
     * Abort unless the caller's org owns the server AND they may change it.
     */
    protected function authorizeServerExecution(Request $request, Server $server): void
    {
        $organization = $request->attributes->get('api_organization');

        abort_if($organization === null || $server->organization_id !== $organization->id, 403);
        abort_if($request->user()?->cannot('update', $server) ?? true, 403);
    }

    /**
     * Reading a run back is not executing, but its output is whatever ran on the
     * host, so it needs at least view access rather than org membership alone.
     */
    protected function authorizeServerRead(Request $request, Server $server): void
    {
        $organization = $request->attributes->get('api_organization');

        abort_if($organization === null || $server->organization_id !== $organization->id, 403);
        abort_if($request->user()?->cannot('view', $server) ?? true, 403);
    }

    protected function authorizeSiteRead(Request $request, Site $site): void
    {
        $organization = $request->attributes->get('api_organization');

        abort_if($organization === null || $site->server?->organization_id !== $organization->id, 403);
        abort_if($request->user()?->cannot('view', $site) ?? true, 403);
    }

    /**
     * Same for a site. Ownership is via the site's server, which is the owner of
     * record for every site kind.
     */
    protected function authorizeSiteExecution(Request $request, Site $site): void
    {
        $organization = $request->attributes->get('api_organization');

        abort_if($organization === null || $site->server?->organization_id !== $organization->id, 403);
        abort_if($request->user()?->cannot('update', $site) ?? true, 403);
    }
}
