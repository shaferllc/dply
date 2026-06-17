<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Shared site-create gate used by the server Sites workspace and the
 * sites.create page so blocked reasons stay consistent everywhere.
 */
final class SiteCreateAccess
{
    /**
     * @return array{blocked_reason: string, can_create: bool}
     */
    public static function assess(Server $server, ?User $user = null): array
    {
        $blocked = self::resolveBlockedReason($server, $user);

        return [
            'blocked_reason' => $blocked,
            'can_create' => $blocked === '',
        ];
    }

    public static function canCreate(Server $server, ?User $user = null): bool
    {
        return self::assess($server, $user)['can_create'];
    }

    public static function blockedReason(Server $server, ?User $user = null): string
    {
        return self::assess($server, $user)['blocked_reason'];
    }

    private static function resolveBlockedReason(Server $server, ?User $user = null): string
    {
        $user ??= auth()->user();

        if (! $server->isReady()) {
            return __('This server is still provisioning — site creation unlocks once it reaches the ready state.');
        }

        if ($user === null) {
            return __('You must be signed in to create a site.');
        }

        $org = $user->currentOrganization();

        if ($org === null) {
            return __('No active organization is selected for your account.');
        }

        if ($server->organization_id === null) {
            return __('This server is not linked to an organization.');
        }

        if ((string) $server->organization_id !== (string) $org->id) {
            $server->loadMissing('organization');

            return __('This server belongs to :org. Switch to that organization to create a site here.', [
                'org' => $server->organization->name ?? __('another organization'),
            ]);
        }

        if (! Gate::forUser($user)->allows('update', $server)) {
            return __('You do not have permission to manage this server.');
        }

        if ($org->userIsDeployer($user)) {
            return __('Your role on this organization (deployer) cannot create new sites. Ask an owner or admin.');
        }

        $siteCount = $org->quotaCountedSiteCount();
        $limit = $org->planSiteLimit();
        if ($limit !== null && $siteCount >= $limit) {
            return __('You\'ve hit your plan\'s site limit (:used / :max). Delete an existing site or upgrade to add more.', [
                'used' => $siteCount,
                'max' => $org->maxSitesDisplay(),
            ]);
        }

        return '';
    }
}
