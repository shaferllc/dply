<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle transitions of an org's bundled-products entitlement (free
 * tracely + Lookout). Emitted by {@see \App\Modules\Billing\Services\BundleEntitlementSynchronizer}
 * and consumed both in-process (Lookout) and over a signed webhook (tracely).
 *
 * See docs/adr/bundled-products-sso.md.
 */
enum BundleTransition: string
{
    /** Newly qualified — create the org's workspace/project. */
    case Provisioned = 'bundle.provisioned';

    /** No longer qualifying — freeze access, retain data (reversible). */
    case Suspended = 'bundle.suspended';

    /** Re-qualified after a suspension — unfreeze the existing workspace. */
    case Resumed = 'bundle.resumed';

    /** Past the retention window while suspended — hard-delete the workspace. */
    case Deleted = 'bundle.deleted';
}
