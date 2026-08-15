<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\PagerDuty\Exceptions;

use RuntimeException;

/**
 * PagerDuty answered, but not with success.
 *
 * Constructor names mirror laravel-notification-channels/pagerduty
 * (serviceBadRequest / rateLimit / unknownError) so code catching these from
 * the package's docs behaves the same. The normalised error string from
 * PagerDutyClient rides along for logging and operator-facing copy.
 */
class ApiError extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        int $status = 0,
    ) {
        parent::__construct($error, $status);
    }

    public static function serviceBadRequest(string $error): self
    {
        return new self($error, 400);
    }

    public static function rateLimit(): self
    {
        return new self('http_429', 429);
    }

    public static function unknownError(int $status, string $error = ''): self
    {
        return new self($error !== '' ? $error : 'http_'.$status, $status);
    }
}
