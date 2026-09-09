<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\Intercom\Exceptions;

use App\Modules\Notifications\Channels\Intercom\IntercomMessage;
use RuntimeException;

/**
 * Thrown when toIntercom() produced a message Intercom would reject outright —
 * missing body, from, or to. Name and constructor shape match
 * laravel-notification-channels/intercom so catch blocks copied from that
 * package's docs still work.
 */
class MessageIsNotCompleteException extends RuntimeException
{
    public function __construct(
        public readonly IntercomMessage $intercomMessage,
        string $description = '',
    ) {
        parent::__construct($description);
    }
}
