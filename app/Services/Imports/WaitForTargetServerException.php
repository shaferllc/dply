<?php

declare(strict_types=1);

namespace App\Services\Imports;

use RuntimeException;

/**
 * Thrown by SSH-dependent step handlers when the dply target server is not
 * yet in STATUS_READY. The orchestrator catches this and leaves the step in
 * `pending` rather than marking it failed — the ServerObserver re-dispatches
 * the step when the server transitions to READY.
 */
final class WaitForTargetServerException extends RuntimeException
{
    public function __construct(string $message = 'Target dply server is not yet ready; waiting.')
    {
        parent::__construct($message);
    }
}
