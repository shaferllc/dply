<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\PagerDuty\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The request never reached PagerDuty — DNS, timeout, TLS.
 *
 * Name and static constructor match laravel-notification-channels/pagerduty so
 * catch blocks copied from that package's docs still work.
 */
class CouldNotSendNotification extends RuntimeException
{
    public static function create(Throwable $previous): self
    {
        return new self($previous->getMessage(), (int) $previous->getCode(), $previous);
    }

    /**
     * Upstream has no equivalent: it lets an incomplete message through to a
     * bare 400 from PagerDuty. Failing here says which field is missing.
     */
    public static function incompleteMessage(string $reason): self
    {
        return new self($reason);
    }
}
