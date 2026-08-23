<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Enums\QuotaSurface;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Shared site-create gate used by the server Sites workspace and the
 * sites.create page so blocked reasons stay consistent everywhere.
 */
final class SiteCreateAccess
{
    public const BLOCKED_BY_AUTH = 'auth';

    public const BLOCKED_BY_ORG = 'org';

    public const BLOCKED_BY_ORG_MISMATCH = 'org_mismatch';

    public const BLOCKED_BY_PERMISSION = 'permission';

    public const BLOCKED_BY_PROVISIONING = 'provisioning';

    public const BLOCKED_BY_QUOTA = 'quota';

    public const BLOCKED_BY_ROLE = 'role';

    /**
     * @return array{blocked_reason: string, blocked_by: string, can_create: bool, quota: array{elsewhere: int, index_route: string, limit: int|null, noun: string, noun_plural: string, plan: string, surface: string, used: int}|null}
     */
    public static function assess(Server $server, ?User $user = null): array
    {
        [$blocked, $blockedBy] = self::resolve($server, $user);

        return [
            'blocked_reason' => $blocked,
            'blocked_by' => $blockedBy,
            'can_create' => $blocked === '',
            'quota' => $blockedBy === self::BLOCKED_BY_QUOTA
                ? self::quotaDetail($server, $user ?? auth()->user())
                : null,
        ];
    }

    /**
     * Which gate rejected the request, so callers can render a tailored
     * callout (a plan cap wants an upgrade CTA; a provisioning server does
     * not). Empty string when creation is allowed.
     */
    public static function blockedBy(Server $server, ?User $user = null): string
    {
        return self::assess($server, $user)['blocked_by'];
    }

    public static function canCreate(Server $server, ?User $user = null): bool
    {
        return self::assess($server, $user)['can_create'];
    }

    public static function blockedReason(Server $server, ?User $user = null): string
    {
        return self::assess($server, $user)['blocked_reason'];
    }

    /**
     * @return array{0: string, 1: string} the reason and the gate that produced it
     */
    private static function resolve(Server $server, ?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $server->isReady()) {
            return [__('This server is still provisioning — site creation unlocks once it reaches the ready state.'), self::BLOCKED_BY_PROVISIONING];
        }

        if ($user === null) {
            return [__('You must be signed in to create a site.'), self::BLOCKED_BY_AUTH];
        }

        $org = $user->currentOrganization();

        if ($org === null) {
            return [__('No active organization is selected for your account.'), self::BLOCKED_BY_ORG];
        }

        if ($server->organization_id === null) {
            return [__('This server is not linked to an organization.'), self::BLOCKED_BY_ORG];
        }

        if ((string) $server->organization_id !== (string) $org->id) {
            $server->loadMissing('organization');

            return [__('This server belongs to :org. Switch to that organization to create a site here.', [
                'org' => $server->organization->name ?? __('another organization'),
            ]), self::BLOCKED_BY_ORG_MISMATCH];
        }

        if (! Gate::forUser($user)->allows('update', $server)) {
            return [__('You do not have permission to manage this server.'), self::BLOCKED_BY_PERMISSION];
        }

        if ($org->userIsDeployer($user)) {
            return [__('Your role on this organization (deployer) cannot create new sites. Ask an owner or admin.'), self::BLOCKED_BY_ROLE];
        }

        // Gate on the ceiling this host's creations actually consume. Edge,
        // Cloud and function hosts each have their own — a full Edge quota must
        // not block a VM site, which is exactly what one shared ceiling did.
        $surface = QuotaSurface::forServer($server);
        $used = $org->quotaUsage($surface);
        $limit = $org->quotaLimit($surface);

        if ($limit !== null && $used >= $limit) {
            return [__('You\'ve hit your plan\'s :noun limit (:used / :max). Delete an existing :noun or upgrade to add more.', [
                'noun' => $surface->label(),
                'used' => $used,
                'max' => $org->quotaLimitDisplay($surface),
            ]), self::BLOCKED_BY_QUOTA];
        }

        return ['', ''];
    }

    /**
     * The numbers behind a quota block.
     *
     * `elsewhere` is the count that is NOT on the server being viewed — the
     * ceiling is org-wide, so a block on a server showing "No sites yet" reads
     * as a bug until the callout can say where the usage actually is.
     *
     * @return array{elsewhere: int, index_route: string, limit: int|null, noun: string, noun_plural: string, plan: string, surface: string, used: int}|null
     */
    private static function quotaDetail(Server $server, ?User $user): ?array
    {
        $org = $user?->currentOrganization();

        if ($org === null) {
            return null;
        }

        $surface = QuotaSurface::forServer($server);
        $used = $org->quotaUsage($surface);

        // Edge/Cloud preview sites were rejected here for the same reason
        // quotaUsageBySurface() skipped them; both surfaces are gone.
        $here = $server->sites()->count();

        return [
            'elsewhere' => max(0, $used - $here),
            'index_route' => $surface->indexRouteName(),
            'limit' => $org->quotaLimit($surface),
            'noun' => $surface->label(),
            'noun_plural' => $surface->noun(2),
            'plan' => $org->isBeta() ? 'Beta' : $org->currentSubscriptionPlan()['label'],
            'surface' => $surface->value,
            'used' => $used,
        ];
    }
}
