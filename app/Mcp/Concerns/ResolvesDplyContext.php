<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Enums\SiteType;
use App\Mcp\Exceptions\DplyMcpException;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;

/**
 * Shared auth/org/site context for dply MCP tools AND resources.
 *
 * The `/mcp` route runs behind `auth.api` (App\Http\Middleware\AuthenticateApiToken),
 * which authenticates the dply API token and puts `api_token` + `api_organization`
 * on the underlying HTTP request. The MCP web transport handler runs on that same
 * request, so the global `request()` exposes both here.
 */
trait ResolvesDplyContext
{
    /**
     * The API token authenticated by `auth.api` on the underlying HTTP request.
     */
    protected function token(): ApiToken
    {
        $token = request()->attributes->get('api_token');

        if (! $token instanceof ApiToken) {
            throw new DplyMcpException('Unauthenticated: a valid dply API token is required.');
        }

        return $token;
    }

    /**
     * The organization the token is scoped to. Prefers the request attribute set
     * by `auth.api`; falls back to the token's own relation.
     */
    protected function organization(?ApiToken $token = null): Organization
    {
        $token ??= $this->token();

        $organization = request()->attributes->get('api_organization') ?? $token->organization;

        if (! $organization instanceof Organization) {
            throw new DplyMcpException('No organization is associated with this token.');
        }

        return $organization;
    }

    /**
     * Load a site by id (or slug) and assert it belongs to the token's org.
     */
    protected function resolveSite(string $siteId, ?Organization $organization = null): Site
    {
        $organization ??= $this->organization();

        $site = Site::query()->with('server')->find($siteId)
            ?? Site::query()->with('server')->where('slug', $siteId)->first();

        if (! $site || $site->server?->organization_id !== $organization->id) {
            throw new DplyMcpException("Site \"{$siteId}\" was not found in this organization.");
        }

        return $site;
    }

    /**
     * Load a server by id and assert it belongs to the token's org.
     */
    protected function resolveServer(string $serverId, ?Organization $organization = null): Server
    {
        $organization ??= $this->organization();

        $server = Server::query()->find($serverId);

        if (! $server || $server->organization_id !== $organization->id) {
            throw new DplyMcpException("Server \"{$serverId}\" was not found in this organization.");
        }

        return $server;
    }

    /**
     * Reject sites that are not VM/host sites for SSH/FPM-only operations.
     */
    protected function assertVmSite(Site $site, string $operation): void
    {
        $type = $site->type instanceof SiteType
            ? $site->type
            : SiteType::tryFrom((string) $site->type);

        if (filled($site->edge_backend) || filled($site->serverless_backend) || $type === SiteType::Container) {
            throw new DplyMcpException(
                "{$operation} is only available for VM/host sites, not container, serverless, or edge sites."
            );
        }
    }
}
