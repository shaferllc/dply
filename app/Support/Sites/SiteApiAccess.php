<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Workspaces\WorkspaceRegistry;

/**
 * Project-aware authorization for the HTTP API (and MCP) site endpoints.
 *
 * Tokens are org-scoped, but UI project membership still applies: an org
 * deployer who is not a member of the site's project must not list that site,
 * queue a deploy, or read deploy logs.
 */
final class SiteApiAccess
{
    public static function userCanView(User $user, Site $site, Organization $organization): bool
    {
        if (! self::sameOrganization($site, $organization)) {
            return false;
        }

        $workspace = self::workspaceFor($site);
        if ($workspace === null) {
            return true;
        }

        return $organization->hasAdminAccess($user) || $workspace->hasMember($user);
    }

    public static function userCanDeploy(User $user, Site $site, Organization $organization): bool
    {
        if (! self::sameOrganization($site, $organization)) {
            return false;
        }

        $workspace = self::workspaceFor($site);
        if ($workspace === null) {
            return true;
        }

        if ($organization->hasAdminAccess($user)) {
            return true;
        }

        return in_array($workspace->memberRole($user), [
            WorkspaceMember::ROLE_OWNER,
            WorkspaceMember::ROLE_MAINTAINER,
            WorkspaceMember::ROLE_DEPLOYER,
        ], true);
    }

    public static function workspaceFor(Site $site): ?Workspace
    {
        $id = $site->workspace_id ?: $site->server?->workspace_id;

        return is_string($id) && $id !== ''
            ? app(WorkspaceRegistry::class)->find($id)
            : null;
    }

    private static function sameOrganization(Site $site, Organization $organization): bool
    {
        return $site->server?->organization_id === $organization->id;
    }
}
