<?php

declare(strict_types=1);

namespace App\Services\Imports;

use RuntimeException;

/**
 * Thrown by SSH-dependent step handlers when the dply target Site hasn't
 * been provisioned yet (ProvisionSiteJob still pending or in flight). The
 * orchestrator catches this and leaves the step PENDING; a Site observer
 * re-dispatches when the Site transitions to a traffic-ready status.
 */
final class WaitForTargetSiteException extends RuntimeException
{
    public function __construct(string $message = 'Target dply site is not yet provisioned; waiting.')
    {
        parent::__construct($message);
    }
}
