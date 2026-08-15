<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\Intercom\Exceptions;

use RuntimeException;

/**
 * Intercom answered, but not with success.
 *
 * Upstream wraps a Guzzle BadResponseException here. We post through Laravel's
 * Http client instead, so this carries the normalised error code from
 * IntercomClient plus the HTTP status — enough for both logging and the
 * operator-facing copy in IntercomClient::describeError().
 */
class RequestException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        int $status = 0,
    ) {
        parent::__construct($error, $status);
    }
}
