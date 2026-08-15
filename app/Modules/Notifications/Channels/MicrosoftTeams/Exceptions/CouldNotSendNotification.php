<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels\MicrosoftTeams\Exceptions;

use RuntimeException;

/**
 * Name matches laravel-notification-channels/microsoft-teams so catch blocks
 * carried over from package-shaped code keep working, even though the payload
 * underneath is now an Adaptive Card rather than a MessageCard.
 */
class CouldNotSendNotification extends RuntimeException
{
    public static function incompleteMessage(string $reason): self
    {
        return new self($reason);
    }

    public static function serviceRejected(string $description, int $status = 0): self
    {
        return new self($description, $status);
    }
}
