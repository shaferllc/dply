<?php

namespace App\Enums;

use App\Models\Site;

/**
 * A deployment method is a named cell in the placement × cutover matrix
 * (see docs/DEPLOYMENT_METHODS.md). Each method resolves to a `placement`
 * (how the release lands on disk) and a `cutover` (how traffic moves to it),
 * plus the capabilities the host must have for it to be offered.
 *
 * `deploy_strategy` (simple|atomic) remains the on-disk placement primitive the
 * existing engines key on; this enum is the richer, user-facing choice and maps
 * back to a strategy via {@see deployStrategy()}.
 */
enum DeploymentMethod: string
{
    case Flat = 'flat';
    case Atomic = 'atomic';
    case Maintenance = 'maintenance';
    case Recreate = 'recreate';
    case BlueGreen = 'blue_green';
    case Rolling = 'rolling';
    case Canary = 'canary';
    case Image = 'image';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Flat (in-place)',
            self::Atomic => 'No-downtime (atomic)',
            self::Maintenance => 'Maintenance window',
            self::Recreate => 'Recreate (stop → start)',
            self::BlueGreen => 'Blue-green',
            self::Rolling => 'Rolling',
            self::Canary => 'Canary',
            self::Image => 'Container image',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Flat => 'Updates the live checkout in place. Simplest; a brief window where files are mid-update.',
            self::Atomic => 'Builds a fresh release directory and flips the current symlink — no downtime, instant rollback.',
            self::Maintenance => 'Raises the maintenance page, deploys, lowers it — for exclusive or destructive migrations.',
            self::Recreate => 'Stops the runtime, deploys, starts it. Accepts downtime; correct for stateful apps.',
            self::BlueGreen => 'Keeps two release trees; builds the idle one, health-checks it, then flips traffic in one switch.',
            self::Rolling => 'Deploys node-by-node behind the load balancer — capacity stays up; a bad release is caught early.',
            self::Canary => 'Sends a subset of traffic to the new release, watches health/errors, then promotes or rolls back.',
            self::Image => 'Builds and runs a container image per release.',
        };
    }

    /** How the release lands on disk/host. */
    public function placement(): string
    {
        return match ($this) {
            self::Flat => 'flat',
            self::BlueGreen => 'blue_green',
            self::Image => 'image',
            default => 'atomic',
        };
    }

    /** How traffic moves to the new release. */
    public function cutover(): string
    {
        return match ($this) {
            self::Rolling => 'rolling',
            self::Canary => 'canary',
            self::Maintenance => 'maintenance',
            self::Recreate => 'recreate',
            default => 'instant',
        };
    }

    /**
     * The on-disk placement primitive the existing deploy engines key on.
     * Everything that isn't an in-place flat deploy uses the atomic release
     * layout as its base (blue-green/image refine it further at their layer).
     */
    public function deployStrategy(): string
    {
        return $this->placement() === 'flat' ? 'simple' : 'atomic';
    }

    /** Whether the method's engine is wired up (vs registered-but-scaffolded). */
    public function isImplemented(): bool
    {
        return match ($this) {
            self::Flat, self::Atomic, self::Maintenance, self::Recreate => true,
            self::BlueGreen, self::Rolling, self::Canary, self::Image => false,
        };
    }

    /** Derive the method from a legacy deploy_strategy value. */
    public static function fromStrategy(?string $strategy): self
    {
        return ($strategy ?? 'simple') === 'atomic' ? self::Atomic : self::Flat;
    }

    /** Resolve a site's effective method (explicit column, else derived). */
    public static function forSite(Site $site): self
    {
        $explicit = $site->getAttribute('deploy_method');
        if (is_string($explicit) && $explicit !== '') {
            $method = self::tryFrom($explicit);
            if ($method !== null) {
                return $method;
            }
        }

        return self::fromStrategy($site->deploy_strategy);
    }

    /**
     * Is this method offerable for the given site's infrastructure? Unsupported
     * or not-yet-implemented methods are hidden, never shown-then-errored.
     */
    public function availableFor(Site $site): bool
    {
        if (! $this->isImplemented()) {
            return false;
        }

        $server = $site->server;
        if ($server === null || ! $server->hostCapabilities()->supportsSsh()) {
            // Flat/atomic/maintenance/recreate all need host SSH to drive the
            // deploy. Container/Cloud sites get their own method set later.
            return false;
        }

        return match ($this) {
            // Rolling / canary need ≥2 backends (a load balancer or worker pool);
            // gated here once those engines land.
            self::Rolling, self::Canary => false,
            default => true,
        };
    }

    /** Methods a site can currently choose, in display order. */
    public static function availableForSite(Site $site): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $m): bool => $m->availableFor($site),
        ));
    }
}
