<?php

declare(strict_types=1);

namespace App\Models;

/**
 * The Discord permission bits dply asks a guild for.
 *
 * Kept deliberately minimal: read the channel list, write a message. Anything
 * more shows up on Discord's install screen as a scarier consent prompt, and an
 * alert bot has no business managing members or messages.
 */
final class DiscordPermissions
{
    /** VIEW_CHANNEL — without it the bot cannot even enumerate channels. */
    public const VIEW_CHANNEL = 1024;

    /** SEND_MESSAGES */
    public const SEND_MESSAGES = 2048;

    /** The bitfield sent as `permissions=` on the authorize URL. */
    public const REQUIRED = self::VIEW_CHANNEL | self::SEND_MESSAGES;
}
