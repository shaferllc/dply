<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One Telegram chat the dply bot has been admitted to, owned by a User,
 * Organization or Team.
 *
 * Like {@see DiscordInstallation} this row holds no secret — the bot token is
 * application-wide config. Unlike both Slack and Discord, there was no OAuth
 * round trip to produce it: Telegram exposes no way to enumerate a bot's chats,
 * so a chat becomes known only when someone runs `/start <token>` where the bot
 * can see it. That inbound `/start` is what creates this row.
 *
 * @property string $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $chat_id
 * @property string $chat_title
 * @property string $chat_type
 * @property ?string $connected_by_user_id
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Model $owner
 */
class TelegramInstallation extends Model
{
    use HasUlids;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'chat_id',
        'chat_title',
        'chat_type',
        'connected_by_user_id',
    ];

    /** A one-to-one DM with the operator, rather than a group or channel. */
    public function isDirectMessage(): bool
    {
        return $this->chat_type === 'private';
    }

    /**
     * Channels post as a bot admin rather than an ordinary member, so a channel
     * connection that looks healthy can still fail until the bot is promoted.
     */
    public function requiresAdmin(): bool
    {
        return $this->chat_type === 'channel';
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
